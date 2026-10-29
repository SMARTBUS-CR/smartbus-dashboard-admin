<?php

use Pest\Rector\Set\PestSetList;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/tests'])
    ->withSets([
        PestSetList::CODING_STYLE,
    ]);
