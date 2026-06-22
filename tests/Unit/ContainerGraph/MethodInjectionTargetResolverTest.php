<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph;

use Neo4j\LaravelBoost\ContainerGraph\MethodInjectionTargetResolver;
use Neo4j\LaravelBoost\Tests\TestCase;
use ReflectionClass;

class MethodInjectionTargetResolverTest extends TestCase
{
    private MethodInjectionTargetResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new MethodInjectionTargetResolver;
    }

    public function test_controller_exposes_public_action_methods(): void
    {
        $methods = $this->resolver->methodsForClass(new ReflectionClass(Fixtures\MethodInjectionPostController::class));

        $this->assertSame(['index', 'store'], $methods);
    }

    public function test_command_job_listener_and_middleware_resolve_handle(): void
    {
        $this->assertSame(['handle'], $this->resolver->methodsForClass(new ReflectionClass(Fixtures\MethodInjectionCommand::class)));
        $this->assertSame(['handle'], $this->resolver->methodsForClass(new ReflectionClass(Fixtures\MethodInjectionJob::class)));
        $this->assertSame(['handle'], $this->resolver->methodsForClass(new ReflectionClass(Fixtures\MethodInjectionListener::class)));
        $this->assertSame(['handle'], $this->resolver->methodsForClass(new ReflectionClass(Fixtures\Middleware\MethodInjectionMiddleware::class)));
    }

    public function test_unrelated_service_has_no_target_methods(): void
    {
        $this->assertSame([], $this->resolver->methodsForClass(new ReflectionClass(\stdClass::class)));
    }
}

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures;

use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Routing\Controller;

final class MethodInjectionPostController extends Controller
{
    public function store(): void {}

    public function index(): void {}

    private function helper(): void {}
}

final class MethodInjectionCommand extends Command
{
    protected $signature = 'fixture:run';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}

final class MethodInjectionJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void {}
}

final class MethodInjectionListener
{
    public function handle(object $event): void {}
}

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MethodInjectionMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
