<?php

namespace Neo4j\LaravelBoost\Tests\Unit\StaticAnalysis;

use Illuminate\Support\Facades\App;
use Neo4j\LaravelBoost\StaticAnalysis\AppFacadeClassChecker;
use Neo4j\LaravelBoost\Tests\TestCase;

class AppFacadeClassCheckerTest extends TestCase
{
    public function test_matches_app_facade_class(): void
    {
        $this->assertTrue(AppFacadeClassChecker::is(App::class));
    }

    public function test_matches_subclass_of_app_facade(): void
    {
        $subclass = new class extends App {};

        $this->assertTrue(AppFacadeClassChecker::is($subclass::class));
    }

    public function test_rejects_unrelated_class(): void
    {
        $this->assertFalse(AppFacadeClassChecker::is(self::class));
    }
}
