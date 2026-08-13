<?php

/**
 * Rebuilds src/ and composer.json from the upstream PHP-WebRTC packages.
 *
 * Each package is cloned at its pinned tag, its sources are copied under src/<package>/, and
 * Rector then downgrades the whole tree to PHP 8.1. Nothing here is edited by hand - to pick up
 * a new upstream release, bump the version in packages.json and run this again.
 *
 * Usage: php tools/build.php
 */

declare(strict_types=1);

const ROOT = __DIR__ . "/..";

$packages = json_decode(file_get_contents(__DIR__ . "/packages.json"), true, flags: JSON_THROW_ON_ERROR);

$workDir = sys_get_temp_dir() . "/webrtc-downgrade-build";
run("rm -rf " . escapeshellarg($workDir) . " " . escapeshellarg(ROOT . "/src") . " " . escapeshellarg(ROOT . "/licenses"));
mkdir($workDir, 0777, true);
mkdir(ROOT . "/src", 0777, true);

$psr4 = [];
$replace = [];
$requires = [];
$licenseNames = [];

foreach($packages as $package){
	$short = shortName($package["name"]);
	$checkout = "$workDir/$short";

	echo "==> {$package["name"]} {$package["version"]}\n";
	run(sprintf(
		"git clone --quiet --depth 1 --branch %s %s %s",
		escapeshellarg($package["version"]),
		escapeshellarg($package["repository"]),
		escapeshellarg($checkout)
	));

	$manifest = json_decode(file_get_contents("$checkout/composer.json"), true, flags: JSON_THROW_ON_ERROR);
	foreach($manifest["require"] ?? [] as $name => $constraint){
		//php is pinned by this package and the sibling requirements disappear into the merge
		if($name === "php" || str_starts_with($name, "quasarstream/")){
			continue;
		}
		$requires[$name][$constraint] = true;
	}
	$replace[$package["name"]] = $package["version"];

	//BSD-3-Clause requires the copyright notice and disclaimer to travel with redistributions
	$licenses = array_merge(
		glob("$checkout/LICENSE*") ?: [],
		glob("$checkout/NOTICE*") ?: []
	);
	if($licenses === []){
		fwrite(STDERR, "no license file in {$package["name"]}\n");
		exit(1);
	}
	run(sprintf("mkdir -p %s", escapeshellarg(ROOT . "/licenses/$short")));
	foreach($licenses as $license){
		copy($license, ROOT . "/licenses/$short/" . basename($license));
	}
	$declared = $manifest["license"] ?? [];
	foreach((array) $declared as $license){
		$licenseNames[$license] = true;
	}

	foreach($package["psr4"] as $namespace => $directory){
		$source = "$checkout/" . trim($directory, "/");
		if(!is_dir($source)){
			fwrite(STDERR, "missing source directory $source\n");
			exit(1);
		}
		$target = "src/$short/" . trim($directory, "/");
		run(sprintf("mkdir -p %s", escapeshellarg(dirname(ROOT . "/$target"))));
		//the upstream repositories occasionally carry a committed vendor/ directory
		run(sprintf(
			"rsync -a --exclude vendor --exclude tests %s/ %s/",
			escapeshellarg($source),
			escapeshellarg(ROOT . "/$target")
		));
		$psr4[$namespace] = "$target/";
	}
}

ksort($psr4);
ksort($replace);
ksort($licenseNames);

echo "==> rector\n";
run(sprintf("cd %s && vendor/bin/rector process --config rector.php --no-progress-bar --no-diffs", escapeshellarg(__DIR__)));

//MoveTraitConstantsRector emits a companion class beside the trait it came from, which PSR-4
//cannot find because the file is named after the trait. Classmap just those files.
$classmap = [];
foreach(iterateSources(ROOT . "/src") as $file){
	foreach(declaredTypes($file) as $type){
		if($type !== basename($file, ".php")){
			$classmap[] = ltrim(substr($file, strlen(ROOT)), "/");
			continue 2;
		}
	}
}
sort($classmap);

$flatRequires = ["php" => "^8.1"];
foreach($requires as $name => $constraints){
	if(count($constraints) > 1){
		fwrite(STDERR, "conflicting constraints for $name: " . implode(", ", array_keys($constraints)) . "\n");
		exit(1);
	}
	$flatRequires[$name] = array_key_first($constraints);
}
//Rector cannot always rewrite array_any()/array_find() when they sit inside a condition
$flatRequires["symfony/polyfill-php84"] = "^1.31";
ksort($flatRequires);

file_put_contents(ROOT . "/composer.json", json_encode([
	"name" => "altayofficial/webrtc",
	"description" => "PHP-WebRTC downgraded to run on PHP 8.1 and later. Generated - see tools/build.php.",
	"type" => "library",
	"license" => array_keys($licenseNames),
	"require" => $flatRequires,
	"replace" => $replace,
	"autoload" => array_filter([
		"psr-4" => $psr4,
		"classmap" => $classmap
	])
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

echo "==> " . count($packages) . " packages, " . count($psr4) . " namespaces, " . count($classmap) . " classmapped files\n";

/**
 * @return iterable<string>
 */
function iterateSources(string $directory) : iterable{
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
	foreach($iterator as $file){
		if($file->isFile() && $file->getExtension() === "php"){
			yield $file->getPathname();
		}
	}
}

/**
 * Returns the names of every class, interface, trait and enum declared at the top level of a file.
 *
 * @return string[]
 */
function declaredTypes(string $file) : array{
	$tokens = token_get_all(file_get_contents($file));
	$types = [];
	$count = count($tokens);
	for($i = 0; $i < $count; $i++){
		$token = $tokens[$i];
		if(!is_array($token) || !in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)){
			continue;
		}
		//skip ::class and anonymous classes
		$previous = previousMeaningful($tokens, $i);
		if($previous !== null && is_array($previous) && $previous[0] === T_DOUBLE_COLON){
			continue;
		}
		for($j = $i + 1; $j < $count; $j++){
			if(is_array($tokens[$j]) && $tokens[$j][0] === T_STRING){
				$types[] = $tokens[$j][1];
				break;
			}
			if(!is_array($tokens[$j]) || $tokens[$j][0] !== T_WHITESPACE){
				break;
			}
		}
	}
	return $types;
}

/**
 * @param array<int, array{int, string, int}|string> $tokens
 * @return array{int, string, int}|string|null
 */
function previousMeaningful(array $tokens, int $index){
	for($i = $index - 1; $i >= 0; $i--){
		if(is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)){
			continue;
		}
		return $tokens[$i];
	}
	return null;
}

function shortName(string $package) : string{
	return substr($package, strpos($package, "/") + 1);
}

function run(string $command) : void{
	passthru($command, $status);
	if($status !== 0){
		fwrite(STDERR, "command failed: $command\n");
		exit($status);
	}
}
