<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph;

use Illuminate\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Neo4j\LaravelBoost\ContainerGraph\AuthorizationExtractor;
use Neo4j\LaravelBoost\Tests\TestCase;
use Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Authorization\InvokableAbility;
use Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Authorization\Post;
use Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Authorization\PostPolicy;

class AuthorizationExtractorTest extends TestCase
{
    public function test_extracts_policies_and_class_based_abilities(): void
    {
        /** @var Gate $gate */
        $gate = $this->app->make(GateContract::class);
        $gate->policy(Post::class, PostPolicy::class);
        $gate->define('publish-post', PostPolicy::class.'@update');
        $gate->define('ping', InvokableAbility::class);

        $extracted = (new AuthorizationExtractor)->extract($gate);

        $this->assertCount(1, $extracted['policies']);
        $policy = $extracted['policies'][0];
        $this->assertSame(Post::class, $policy['key']);
        $this->assertSame('Post', $policy['name']);
        $this->assertSame(Post::class, $policy['model']);
        $this->assertSame('Class', $policy['model_kind']);
        $this->assertSame(PostPolicy::class, $policy['identifier']);
        $this->assertSame('Class', $policy['identifier_kind']);
        $this->assertSame(PostPolicy::class, $policy['action']);

        $byKey = [];
        foreach ($extracted['abilities'] as $row) {
            $byKey[$row['key']] = $row;
        }

        $this->assertSame('class', $byKey['publish-post']['handler_kind']);
        $this->assertSame(PostPolicy::class, $byKey['publish-post']['identifier']);
        $this->assertSame(PostPolicy::class.'@update', $byKey['publish-post']['action']);

        $this->assertSame('class', $byKey['ping']['handler_kind']);
        $this->assertSame(InvokableAbility::class, $byKey['ping']['identifier']);
        $this->assertSame(InvokableAbility::class.'@__invoke', $byKey['ping']['action']);
    }

    public function test_extracts_resource_abilities_and_closure_abilities(): void
    {
        /** @var Gate $gate */
        $gate = $this->app->make(GateContract::class);
        $gate->resource('post', PostPolicy::class);
        $gate->define('board-the-plane', fn (): bool => true);

        $extracted = (new AuthorizationExtractor)->extract($gate);
        $byKey = [];
        foreach ($extracted['abilities'] as $row) {
            $byKey[$row['key']] = $row;
        }

        $this->assertSame(PostPolicy::class.'@update', $byKey['post.update']['action']);
        $this->assertSame(PostPolicy::class, $byKey['post.update']['identifier']);
        $this->assertSame('class', $byKey['post.update']['handler_kind']);

        $this->assertSame('closure', $byKey['board-the-plane']['handler_kind']);
        $this->assertSame('', $byKey['board-the-plane']['identifier']);
        $this->assertSame('', $byKey['board-the-plane']['action']);
    }

    public function test_reads_live_gate_by_default(): void
    {
        /** @var Gate $gate */
        $gate = $this->app->make(GateContract::class);
        $gate->policy(Post::class, PostPolicy::class);

        $extracted = (new AuthorizationExtractor)->extract();

        $this->assertNotEmpty($extracted['policies']);
        $this->assertSame(Post::class, $extracted['policies'][0]['key']);
    }
}
