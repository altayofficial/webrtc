<?php

declare(strict_types=1);

use Altay\WebrtcDowngrade\MoveTraitConstantsRector;
use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\DowngradeLevelSetList;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
	->withPhpVersion(PhpVersion::PHP_81)
	->withSets([DowngradeLevelSetList::DOWN_TO_PHP_81])
	->withRules([MoveTraitConstantsRector::class])
	->withPaths([__DIR__ . "/../src"]);
