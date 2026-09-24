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

    public function test_laravel_skeleton_controller_without_routing_base_exposes_actions(): void
    {
        $methods = $this->resolver->methodsForClass(
            new ReflectionClass(Fixtures\Http\Controllers\SkeletonPostController::class),
        );

        $this->assertSame(['index', 'store'], $methods);
    }

    public function test_command_job_listener_and_middleware_resolve_handle(): void
    {
        $this->assertSame(['handle'], $this->resolver->methodsForClass(new ReflectionClass(Fixtures\MethodInjectionCommand::class)));
        $this->assertSame(['handle'], $this->resolver->methodsForClass(new ReflectionClass(Fixtures\MethodInjectionJob::class)));
        $this->assertSame(['handle'], $this->resolver->methodsForClass(new ReflectionClass(Fixtures\MethodInjectionListener::class)));
        $this->assertSame(['handle'], $this->resolver->methodsForClass(new ReflectionClass(Fixtures\Middleware\MethodInjectionMiddleware::class)));
    }

    public function test_queued_listener_is_classified_as_listener_not_job(): void
    {
        $queuedListener = new ReflectionClass(Fixtures\MethodInjectionQueuedListener::class);

        $this->assertTrue($this->resolver->isListener($queuedListener));
        $this->assertFalse($this->resolver->isJob($queuedListener));
        $this->assertSame(['handle'], $this->resolver->methodsForClass($queuedListener));
    }

    public function test_broadcast_channel_resolves_join_method(): void
    {
        $channel = new ReflectionClass(Fixtures\Broadcasting\MethodInjectionOrderChannel::class);

        $this->assertTrue($this->resolver->isBroadcastChannel($channel));
        $this->assertSame(['join'], $this->resolver->methodsForClass($channel));
    }

    public function test_queued_notification_is_classified_as_notification_not_job(): void
    {
        $queuedNotification = new ReflectionClass(Fixtures\Notifications\QueuedInvoiceNotification::class);

        $this->assertTrue($this->resolver->isNotification($queuedNotification));
        $this->assertFalse($this->resolver->isJob($queuedNotification));
        $this->assertSame(['via'], $this->resolver->methodsForClass($queuedNotification));
    }

    public function test_notification_exposes_via_and_to_methods(): void
    {
        $methods = $this->resolver->methodsForClass(
            new ReflectionClass(Fixtures\Notifications\InvoicePaidNotification::class),
        );

        $this->assertSame(['via', 'toMail', 'toArray'], $methods);
    }

    public function test_queued_mailable_is_classified_as_mailable_not_job(): void
    {
        $queuedMailable = new ReflectionClass(Fixtures\MethodInjectionQueuedMailable::class);

        $this->assertTrue($this->resolver->isMailable($queuedMailable));
        $this->assertFalse($this->resolver->isJob($queuedMailable));
        $this->assertSame(['build'], $this->resolver->methodsForClass($queuedMailable));
    }

    public function test_modern_mailable_exposes_envelope_content_and_attachments(): void
    {
        $mailable = new ReflectionClass(Fixtures\MethodInjectionModernMailable::class);

        $this->assertSame(
            ['envelope', 'content', 'attachments'],
            $this->resolver->methodsForClass($mailable),
        );
        $this->assertSame('envelope', $this->resolver->resolveMailableHandlerMethod($mailable));
    }

    public function test_constructor_only_mailable_has_empty_handler_and_no_injection_targets(): void
    {
        $mailable = new ReflectionClass(Fixtures\MethodInjectionConstructorOnlyMailable::class);

        $this->assertSame('', $this->resolver->resolveMailableHandlerMethod($mailable));
        $this->assertSame([], $this->resolver->methodsForClass($mailable));
    }

    public function test_mailable_inherits_build_from_app_base_for_injection(): void
    {
        $mailable = new ReflectionClass(Fixtures\MethodInjectionChildMailable::class);

        $this->assertSame(['build'], $this->resolver->methodsForClass($mailable));
        $this->assertSame('build', $this->resolver->resolveMailableHandlerMethod($mailable));
    }

    public function test_invokable_job_resolves_invoke_method(): void
    {
        $this->assertSame(['__invoke'], $this->resolver->methodsForClass(new ReflectionClass(Fixtures\MethodInjectionInvokableJob::class)));
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
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Events\OrderShipped;

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

final class MethodInjectionQueuedListener implements ShouldQueue
{
    public function handle(OrderShipped $event): void {}
}

final class MethodInjectionInvokableJob implements ShouldQueue
{
    public function __invoke(): void {}
}

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Broadcasting;

final class MethodInjectionOrderChannel
{
    public function join(object $user, string $orderId): bool
    {
        return true;
    }
}

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class MethodInjectionQueuedMailable extends Mailable implements ShouldQueue
{
    use Queueable;

    public function build(): self
    {
        return $this->subject('Queued')->view('mail.queued');
    }
}

final class MethodInjectionModernMailable extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Welcome');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.welcome');
    }

    /**
     * @return list<object>
     */
    public function attachments(): array
    {
        return [];
    }
}

final class MethodInjectionConstructorOnlyMailable extends Mailable
{
    public function __construct(public string $title = 'hi') {}
}

abstract class MethodInjectionAppBaseMailable extends Mailable
{
    public function build(): self
    {
        return $this->subject('Base')->view('mail.base');
    }
}

final class MethodInjectionChildMailable extends MethodInjectionAppBaseMailable {}

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
