#!/usr/bin/env php
<?php

/**
 * E2E test: contribute-graph-knowledge against a Laravel app (e.g. billing-management-system).
 *
 * Run from the Laravel project root:
 *   php scripts/e2e-contribute-graph-knowledge.php
 *
 * Optional env:
 *   E2E_FROM_CLASS  - source FQCN (default: App\Services\InvoiceService)
 *   E2E_TO_CLASS    - target FQCN (default: App\Services\ApiKeyService)
 */

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Neo4j\LaravelBoost\Boost\Tools\ContributeGraphKnowledgeTool;
use Neo4j\LaravelBoost\Boost\Tools\GetClassDependencyGraphTool;
use Neo4j\LaravelBoost\GraphKnowledgeContributor;
use Neo4j\LaravelBoost\Support\ContainerGraphConnection;
use Neo4j\LaravelBoost\Support\Graph\DependsOnType;

$root = getcwd() ?: '';
if (! is_file($root.'/vendor/autoload.php')) {
    fwrite(STDERR, "Run from a Laravel project root (vendor/autoload.php not found).\n");
    exit(1);
}

require $root.'/vendor/autoload.php';

/** @var Application $app */
$app = require $root.'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

$fromClass = (string) (getenv('E2E_FROM_CLASS') ?: 'App\\Services\\InvoiceService');
$toClass = (string) (getenv('E2E_TO_CLASS') ?: 'App\\Services\\ApiKeyService');

$passed = 0;
$failed = 0;

function e2e_line(string $message): void
{
    echo $message.PHP_EOL;
}

function e2e_pass(string $name, string $detail = ''): void
{
    global $passed;
    $passed++;
    e2e_line('[PASS] '.$name.($detail !== '' ? ' — '.$detail : ''));
}

function e2e_fail(string $name, string $detail): never
{
    global $failed;
    $failed++;
    e2e_line('[FAIL] '.$name.' — '.$detail);
    e2e_summary();
    exit(1);
}

function e2e_summary(): void
{
    global $passed, $failed;
    e2e_line('');
    e2e_line(sprintf('Results: %d passed, %d failed', $passed, $failed));
}

/**
 * @return array<string, mixed>
 */
function decode_tool_response(Response $response): array
{
    if ($response->isError()) {
        $text = $response->content()->toArray()['text'] ?? 'unknown error';

        throw new RuntimeException((string) $text);
    }

    $text = $response->content()->toArray()['text'] ?? '';
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) $text, true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

/**
 * @return array<string, mixed>
 */
function call_contribute(ContributeGraphKnowledgeTool $tool, array $arguments): array
{
    return decode_tool_response($tool->handle(new Request($arguments)));
}

/**
 * @return array<string, mixed>
 */
function call_get_graph(GetClassDependencyGraphTool $tool, string $class): array
{
    return decode_tool_response($tool->handle(new Request([
        'class' => $class,
        'direction' => 'outbound',
        'depth' => 1,
        'per_page' => 500,
    ])));
}

/**
 * @param  array<int, array<string, mixed>>  $dependencies
 */
function find_dependency(array $dependencies, string $name): ?array
{
    foreach ($dependencies as $dependency) {
        if (($dependency['name'] ?? '') === $name) {
            return $dependency;
        }
    }

    return null;
}

e2e_line('╔══════════════════════════════════════════════════════════════╗');
e2e_line('║  Neo4j Boost — contribute-graph-knowledge E2E                ║');
e2e_line('╚══════════════════════════════════════════════════════════════╝');
e2e_line('');
e2e_line('From: '.$fromClass);
e2e_line('To:   '.$toClass);
e2e_line('');

$include = config('boost.mcp.tools.include', []);
if (! in_array(ContributeGraphKnowledgeTool::class, $include, true)) {
    e2e_fail('tool registration', 'ContributeGraphKnowledgeTool not in boost.mcp.tools.include');
}
e2e_pass('tool registration', ContributeGraphKnowledgeTool::class);

try {
    $connection = $app->make(ContainerGraphConnection::class);
    $connection->connect();
    $connection->run('RETURN 1 AS ok');
    e2e_pass('neo4j connection');
} catch (Throwable $e) {
    e2e_fail('neo4j connection', $e->getMessage());
}

try {
    $exitCode = Artisan::call('container:graph');
    if ($exitCode !== 0) {
        e2e_fail('container:graph', 'exit code '.$exitCode);
    }
    e2e_pass('container:graph export');
} catch (Throwable $e) {
    e2e_fail('container:graph', $e->getMessage());
}

$contribute = $app->make(ContributeGraphKnowledgeTool::class);
$readGraph = $app->make(GetClassDependencyGraphTool::class);

try {
    $baseline = call_get_graph($readGraph, $fromClass);
    if (($baseline['found'] ?? false) !== true) {
        e2e_fail('baseline graph read', 'class not found in graph: '.$fromClass);
    }

    $existing = find_dependency($baseline['dependencies'] ?? [], $toClass);
    if ($existing !== null && ($existing['source'] ?? '') === GraphKnowledgeContributor::SOURCE_USER) {
        e2e_line('[INFO] Removing prior user-contributed test edge for a clean run…');
        $connection->run(
            <<<'CYPHER'
MATCH (c:Abstract {name: $from})-[r:DEPENDS_ON {source: 'user'}]->(d:Abstract {name: $to})
DELETE r
CYPHER,
            ['from' => $fromClass, 'to' => $toClass],
        );
        $baseline = call_get_graph($readGraph, $fromClass);
        $existing = find_dependency($baseline['dependencies'] ?? [], $toClass);
    }

    if ($existing !== null && ($existing['source'] ?? '') !== GraphKnowledgeContributor::SOURCE_USER) {
        e2e_fail('baseline graph read', $toClass.' is already a static dependency of '.$fromClass);
    }

    e2e_pass('baseline graph read', $toClass.' not yet a dependency');
} catch (Throwable $e) {
    e2e_fail('baseline graph read', $e->getMessage());
}

try {
    $proposal = call_contribute($contribute, [
        'relationship' => GraphKnowledgeContributor::RELATIONSHIP_DEPENDS_ON,
        'from' => $fromClass,
        'to' => $toClass,
        'confidence' => GraphKnowledgeContributor::CONFIDENCE_MEDIUM,
        'reason' => 'E2E test: agent-inferred billing dependency',
    ]);

    if (($proposal['status'] ?? '') !== 'confirmation_required') {
        e2e_fail('medium proposal', 'expected confirmation_required, got '.json_encode($proposal));
    }

    $afterProposal = call_get_graph($readGraph, $fromClass);
    if (find_dependency($afterProposal['dependencies'] ?? [], $toClass) !== null) {
        e2e_fail('medium proposal', 'edge was written before user confirmation');
    }

    e2e_pass('medium proposal', 'confirmation_required, no silent write');
} catch (Throwable $e) {
    e2e_fail('medium proposal', $e->getMessage());
}

try {
    $confirmed = call_contribute($contribute, [
        'relationship' => GraphKnowledgeContributor::RELATIONSHIP_DEPENDS_ON,
        'from' => $fromClass,
        'to' => $toClass,
        'confidence' => GraphKnowledgeContributor::CONFIDENCE_MEDIUM,
        'confirmed' => true,
        'reason' => 'E2E test: user confirmed billing dependency',
    ]);

    if (($confirmed['status'] ?? '') !== 'persisted') {
        e2e_fail('user confirm', 'expected persisted, got '.json_encode($confirmed));
    }

    if (($confirmed['source'] ?? '') !== GraphKnowledgeContributor::SOURCE_USER) {
        e2e_fail('user confirm', 'expected source=user, got '.json_encode($confirmed));
    }

    e2e_pass('user confirm', 'persisted with source=user');
} catch (Throwable $e) {
    e2e_fail('user confirm', $e->getMessage());
}

try {
    $graph = call_get_graph($readGraph, $fromClass);
    $dependency = find_dependency($graph['dependencies'] ?? [], $toClass);

    if ($dependency === null) {
        e2e_fail('read back', $toClass.' not found in dependencies of '.$fromClass);
    }

    if (($dependency['source'] ?? '') !== GraphKnowledgeContributor::SOURCE_USER) {
        e2e_fail('read back', 'expected source=user on dependency, got '.json_encode($dependency));
    }

    if (($dependency['type'] ?? '') !== DependsOnType::ServiceLocation->value) {
        e2e_fail('read back', 'expected type=service_location, got '.json_encode($dependency));
    }

    e2e_pass('read back', 'get-class-dependency-graph shows source=user');
} catch (Throwable $e) {
    e2e_fail('read back', $e->getMessage());
}

e2e_line('');
e2e_line('╔══════════════════════════════════════════════════════════════╗');
e2e_line('║  ✓ contribute-graph-knowledge E2E passed                     ║');
e2e_line('╚══════════════════════════════════════════════════════════════╝');
e2e_summary();
exit(0);
