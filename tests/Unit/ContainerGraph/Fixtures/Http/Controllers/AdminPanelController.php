<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Http\Controllers;

final class AdminPanelController
{
    public function index(): string
    {
        return 'users';
    }

    public function health(): string
    {
        return 'ok';
    }
}
