<?php

declare(strict_types=1);

namespace Altay\WebrtcDowngrade;

use PhpParser\Node;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Trait_;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Traits may not declare constants before PHP 8.2. This moves them into a generated companion
 * class placed next to the trait and repoints every self::/static:: reference at it.
 *
 * There is no upstream Rector rule for this, and the alternative - patch files against specific
 * upstream lines - breaks silently whenever the vendor reformats the trait.
 */
final class MoveTraitConstantsRector extends AbstractRector{

	private const COMPANION_SUFFIX = "Constants";

	public function getRuleDefinition() : RuleDefinition{
		return new RuleDefinition(
			"Move constants declared in a trait into a companion class, for PHP 8.1 compatibility",
			[
				new CodeSample(
					<<<'PHP'
trait Foo{
    private const BAR = 1;

    public function baz() : int{
        return self::BAR;
    }
}
PHP,
					<<<'PHP'
final class FooConstants{
    public const BAR = 1;
}

trait Foo{
    public function baz() : int{
        return FooConstants::BAR;
    }
}
PHP
				)
			]
		);
	}

	/**
	 * @return array<class-string<Node>>
	 */
	public function getNodeTypes() : array{
		return [Namespace_::class];
	}

	public function refactor(Node $node) : ?Node{
		$changed = false;
		foreach($node->stmts as $index => $stmt){
			if(!$stmt instanceof Trait_ || $stmt->name === null){
				continue;
			}
			$constants = array_filter($stmt->stmts, static fn(Node $s) : bool => $s instanceof ClassConst);
			if($constants === []){
				continue;
			}

			$companionName = $stmt->name->toString() . self::COMPANION_SUFFIX;
			$namespace = $node->name?->toString();
			$fullyQualified = new FullyQualified($namespace !== null ? $namespace . "\\" . $companionName : $companionName);

			$companion = new Class_($companionName, ["flags" => Class_::MODIFIER_FINAL]);
			//the name resolver has already run by the time this rule inserts the node, so the
			//resolved name has to be filled in by hand or Rector trips over it later
			$companion->namespacedName = $fullyQualified;
			$names = [];
			foreach($constants as $constant){
				//the companion is a separate class, so private and protected constants must open up
				$constant->flags = Class_::MODIFIER_PUBLIC;
				$companion->stmts[] = $constant;
				foreach($constant->consts as $const){
					$names[$const->name->toString()] = true;
				}
			}
			$stmt->stmts = array_values(array_filter($stmt->stmts, static fn(Node $s) : bool => !$s instanceof ClassConst));

			$this->repointReferences($stmt, $names, $fullyQualified);

			array_splice($node->stmts, $index, 0, [$companion]);
			$changed = true;
		}

		return $changed ? $node : null;
	}

	/**
	 * @param array<string, true> $names
	 */
	private function repointReferences(Trait_ $trait, array $names, FullyQualified $target) : void{
		$this->traverseNodesWithCallable($trait, static function(Node $node) use ($names, $target) : ?Node{
			if(!$node instanceof Node\Expr\ClassConstFetch || !$node->name instanceof Node\Identifier){
				return null;
			}
			if(!isset($names[$node->name->toString()])){
				return null;
			}
			if(!$node->class instanceof Node\Name){
				return null;
			}
			$class = $node->class->toString();
			if($class !== "self" && $class !== "static"){
				return null;
			}
			$node->class = $target;
			return $node;
		});
	}
}
