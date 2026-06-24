<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph;

use Closure;
use Illuminate\Http\Request;
use Neo4j\LaravelBoost\ContainerGraph\MethodInjectionExtractor;
use Neo4j\LaravelBoost\ContainerGraph\MethodInjectionTargetResolver;
use Neo4j\LaravelBoost\ContainerGraph\ParameterDependencyResolver;
use Neo4j\LaravelBoost\Support\Graph\DependsOnType;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Controllers\PostController;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Events\OrderShipped;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Http\Requests\StorePostRequest;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Jobs\ProcessInvoiceJob;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Listeners\OrderShippedListener;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Middleware\VerifyJsonApi;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\Logger;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\PodcastParser;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\TokenVerifier;
use Neo4j\LaravelBoost\Tests\TestCase;

class MethodInjectionExtractorTest extends TestCase
{
    public function test_extractor_emits_method_injection_rows_with_method_and_parameter(): void
    {
        $extractor = new MethodInjectionExtractor(
            new MethodInjectionTargetResolver,
            new ParameterDependencyResolver,
        );

        [$rows] = $extractor->extract([
            PostController::class,
            ProcessInvoiceJob::class,
            VerifyJsonApi::class,
            OrderShippedListener::class,
        ]);

        $storeFormRequest = $this->findRow($rows, PostController::class, StorePostRequest::class);
        $this->assertNotNull($storeFormRequest);
        $this->assertSame(DependsOnType::MethodInjection->value, $storeFormRequest['type']);
        $this->assertSame('store', $storeFormRequest['method']);
        $this->assertSame('request', $storeFormRequest['parameter']);

        $storeParser = $this->findRow($rows, PostController::class, PodcastParser::class, 'store');
        $this->assertNotNull($storeParser);
        $this->assertSame('store', $storeParser['method']);
        $this->assertSame('parser', $storeParser['parameter']);

        $jobLogger = $this->findRow($rows, ProcessInvoiceJob::class, Logger::class);
        $this->assertNotNull($jobLogger);
        $this->assertSame('handle', $jobLogger['method']);
        $this->assertSame('logger', $jobLogger['parameter']);

        $middlewareVerifier = $this->findRow($rows, VerifyJsonApi::class, TokenVerifier::class);
        $this->assertNotNull($middlewareVerifier);
        $this->assertSame('handle', $middlewareVerifier['method']);
        $this->assertSame('verifier', $middlewareVerifier['parameter']);

        $this->assertNull($this->findRow($rows, VerifyJsonApi::class, Closure::class));
        $this->assertNull($this->findRow($rows, VerifyJsonApi::class, Request::class));
        $this->assertNull($this->findRow($rows, OrderShippedListener::class, OrderShipped::class));

        $listenerLogger = $this->findRow($rows, OrderShippedListener::class, Logger::class);
        $this->assertNotNull($listenerLogger);
        $this->assertSame('handle', $listenerLogger['method']);
        $this->assertSame('logger', $listenerLogger['parameter']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return null|array<string, mixed>
     */
    private function findRow(array $rows, string $class, string $dependency, ?string $method = null): ?array
    {
        foreach ($rows as $row) {
            if ($row['class'] !== $class || $row['dependency'] !== $dependency) {
                continue;
            }

            if ($method !== null && ($row['method'] ?? '') !== $method) {
                continue;
            }

            return $row;
        }

        return null;
    }
}
