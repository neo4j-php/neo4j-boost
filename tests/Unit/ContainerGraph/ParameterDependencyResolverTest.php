<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph;

use Illuminate\Http\Request;
use Neo4j\LaravelBoost\ContainerGraph\ParameterDependencyResolver;
use PHPUnit\Framework\TestCase;

class ParameterDependencyResolverTest extends TestCase
{
    public function test_middleware_framework_types_are_identified(): void
    {
        $resolver = new ParameterDependencyResolver;

        $this->assertTrue($resolver->isMiddlewareFrameworkType(Request::class));
        $this->assertFalse($resolver->isMiddlewareFrameworkType('App\\Services\\TokenVerifier'));
    }
}
