<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\StaticAnalysis\Services;

final class GlobalHelperWorker
{
    public function warmCache(): void
    {
        cache()->remember('stats', 60, static fn (): array => []);
    }

    public function currentUserId(): ?int
    {
        return auth()->id();
    }

    public function appName(): mixed
    {
        return config('app.name');
    }

    public function appKey(): mixed
    {
        return env('APP_KEY');
    }

    public function logActivity(): void
    {
        logger('activity');
    }
}
