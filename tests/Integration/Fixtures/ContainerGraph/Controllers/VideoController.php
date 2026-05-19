<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Controllers;

use Illuminate\Contracts\Filesystem\Filesystem;

class VideoController
{
    public function __construct(
        protected Filesystem $filesystem,
    ) {}
}
