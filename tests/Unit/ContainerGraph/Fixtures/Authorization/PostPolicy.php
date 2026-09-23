<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Authorization;

final class PostPolicy
{
    public function update(mixed $user, Post $post): bool
    {
        return true;
    }

    public function viewAny(mixed $user): bool
    {
        return true;
    }

    public function view(mixed $user, Post $post): bool
    {
        return true;
    }

    public function create(mixed $user): bool
    {
        return true;
    }

    public function delete(mixed $user, Post $post): bool
    {
        return true;
    }
}
