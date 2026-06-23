<?php

namespace Neo4j\LaravelBoost\Console;

use Closure;
use Illuminate\Console\Command;
use Neo4j\LaravelBoost\ContainerGraph\DependencyChainBuilder;
use Neo4j\LaravelBoost\ContainerGraph\MethodInjectionExtractor;
use Neo4j\LaravelBoost\ContainerGraph\ParameterDependencyResolver;
use Neo4j\LaravelBoost\ContainerGraphWriter;
use Neo4j\LaravelBoost\ResolutionCatalog\FacadeCatalogExporter;
use Neo4j\LaravelBoost\StaticAnalysis\FacadeEdgeFinder;
use Neo4j\LaravelBoost\StaticAnalysis\GlobalHelperEdgeFinder;
use Neo4j\LaravelBoost\StaticAnalysis\ServiceLocationEdgeFinder;
use Neo4j\LaravelBoost\Support\Graph\BindsToType;
use Neo4j\LaravelBoost\Support\Graph\DependsOnType;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionFunction;
use ReflectionNamedType;
use Throwable;

class ContainerGraphCommand extends Command
{
    protected $signature = 'container:graph {--dry-run : Extract only, do not write to Neo4j} {--print-cypher : Print Cypher templates before running}';

    protected $description = 'Export Laravel container wiring into Neo4j for dependency debugging';

    public function __construct(
        private ServiceLocationEdgeFinder $serviceLocationEdgeFinder,
        private FacadeEdgeFinder $facadeEdgeFinder,
        private GlobalHelperEdgeFinder $globalHelperEdgeFinder,
        private DependencyChainBuilder $dependencyChainBuilder,
        private FacadeCatalogExporter $facadeCatalogExporter,
        private MethodInjectionExtractor $methodInjectionExtractor,
        private ParameterDependencyResolver $parameterDependencyResolver,
    ) {
        parent::__construct();
    }

    public function handle(ContainerGraphWriter $writer): int
    {
        [$bindingRows, $concreteClasses] = $this->extractBindingRows();
        $bindings = app()->getBindings();
        $concreteClasses = $this->mergeClassLists($concreteClasses, $this->extractCustomClassNames());
        [$constructorDependencyRows, $constructorUnresolvedRows] = $this->extractConstructorDependencyRows($concreteClasses);
        [$methodInjectionRows, $methodInjectionUnresolvedRows] = $this->methodInjectionExtractor->extract($concreteClasses);
        $dependencyRows = $this->uniqueRows(array_merge($constructorDependencyRows, $methodInjectionRows));
        $unresolvedRows = $this->uniqueRows(array_merge($constructorUnresolvedRows, $methodInjectionUnresolvedRows));
        $staticServiceLocationRows = $this->extractStaticServiceLocationRows();
        $staticFacadeRows = $this->extractStaticFacadeRows();
        $staticGlobalHelperRows = $this->extractStaticGlobalHelperRows();
        $staticDependencyRows = $this->uniqueRows(array_merge(
            $staticServiceLocationRows,
            $staticFacadeRows,
            $staticGlobalHelperRows,
        ));
        $dependencyRows = $this->uniqueRows(array_merge($dependencyRows, $staticDependencyRows));
        $concreteClasses = $this->mergeClassLists(
            $concreteClasses,
            $this->classNamesFromDependencyRows($staticDependencyRows),
        );
        $instanceRows = array_map(
            static fn (string $className): array => ['class' => $className],
            $concreteClasses
        );
        $facadeCatalogRows = $this->facadeCatalogExporter->rowsForAppClasses($concreteClasses);
        $dependencyChainRows = $this->buildDependencyChainRows(
            $dependencyRows,
            $unresolvedRows,
            $bindings,
            $facadeCatalogRows,
        );

        $this->line('Container graph summary:');
        $this->line('- Bindings: '.count($bindingRows));
        $this->line('- Facade catalog entries: '.count($facadeCatalogRows));
        $this->line('- Concrete classes inspected: '.count($concreteClasses));
        $this->line('- Instance nodes: '.count($instanceRows));
        $this->line('- Dependency chains: '.count($dependencyChainRows));
        $this->line('- Method injection edges: '.count($methodInjectionRows));
        $this->line('- Static service_location edges: '.count($staticServiceLocationRows));
        $this->line('- Static facade edges: '.count($staticFacadeRows));
        $this->line('- Static global_helper edges: '.count($staticGlobalHelperRows));
        $this->line('- Unresolved dependencies: '.count($unresolvedRows));

        if ($this->option('print-cypher')) {
            $this->printCypher($writer, $instanceRows, $bindingRows, $dependencyChainRows);
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run complete. No data written to Neo4j.');

            return self::SUCCESS;
        }

        try {
            $writer->connect();
            $writer->write($instanceRows, $bindingRows, $dependencyChainRows);
        } catch (Throwable $e) {
            $this->error('Failed to write container graph: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Container graph written to Neo4j successfully.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array{class: string, dependency: string, dependencyKind: string, type: string, source?: string, via?: string, file?: string, line?: int}>  $dependencyRows
     * @param  array<int, array{class: string, name: string, reason: string, type: string}>  $unresolvedRows
     * @param  array<string, array{concrete: mixed, shared: bool}>  $bindings
     * @param  array<int, array{facade_class: string, abstract: string, abstractKind: string, binding_key: string, source: string, binds_to_type: string}>  $facadeCatalogRows
     * @return array<int, array{instance: string, dependency_key: string, access: string, identifier: string, identifier_kind: string, lifetime: string, injection_type: string, method: string, parameter: string, via: string, file: string, line: int}>
     */
    private function buildDependencyChainRows(
        array $dependencyRows,
        array $unresolvedRows,
        array $bindings,
        array $facadeCatalogRows = [],
    ): array {
        $chains = [];

        foreach ($dependencyRows as $row) {
            $chains[] = $this->dependencyChainBuilder->fromExtractedDependencyRow($row, $bindings);
        }

        foreach ($unresolvedRows as $row) {
            $chains[] = $this->dependencyChainBuilder->fromUnresolvedRow($row, $bindings);
        }

        foreach ($facadeCatalogRows as $row) {
            $chains[] = $this->dependencyChainBuilder->fromFacadeExportRow($row);
        }

        return $this->uniqueRows($chains);
    }

    /**
     * @return array{0: array<int, array{abstract: string, abstractKind: string, concrete: string, concreteKind: string, shared: bool, type: string}>, 1: array<int, string>}
     */
    private function extractBindingRows(): array
    {
        /** @var array<string, array{concrete: mixed, shared: bool}> $bindings */
        $bindings = app()->getBindings();
        $rows = [];
        $concreteClasses = [];

        foreach ($bindings as $abstract => $binding) {
            $resolved = $this->resolveConcreteDescriptor($abstract, $binding['concrete'] ?? null);
            if ($resolved === null) {
                continue;
            }

            $shared = (bool) ($binding['shared'] ?? false);

            $rows[] = [
                'abstract' => $abstract,
                'abstractKind' => $this->kindForTypeName($abstract),
                'concrete' => $resolved['name'],
                'concreteKind' => $resolved['kind'],
                'shared' => $shared,
                'type' => BindsToType::fromShared($shared)->value,
            ];

            if ($resolved['kind'] === 'Class' && class_exists($resolved['name']) && ! interface_exists($resolved['name'])) {
                $concreteClasses[$resolved['name']] = $resolved['name'];
            }
        }

        return [$rows, array_values($concreteClasses)];
    }

    /**
     * @return array<int, string>
     */
    private function extractCustomClassNames(): array
    {
        $composerJson = base_path('composer.json');
        if (! is_file($composerJson)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($composerJson), true);
        if (! is_array($decoded)) {
            return [];
        }

        // Production autoload only: autoload-dev often maps tests/ and Pest bootstrap
        // files (e.g. Tests\Pest) that fatal when class_exists() runs outside ./vendor/bin/pest.
        $autoload = $decoded['autoload']['psr-4'] ?? [];

        $classes = [];

        foreach ($autoload as $namespacePrefix => $paths) {
            if (! is_string($namespacePrefix)) {
                continue;
            }

            $pathList = is_array($paths) ? $paths : [$paths];
            foreach ($pathList as $path) {
                if (! is_string($path) || str_starts_with($path, 'vendor/')) {
                    continue;
                }

                $baseDir = base_path(trim($path, '/'));
                if (! is_dir($baseDir)) {
                    continue;
                }

                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS)
                );

                foreach ($iterator as $file) {
                    if (! $file->isFile() || $file->getExtension() !== 'php') {
                        continue;
                    }

                    $relativePath = ltrim(str_replace($baseDir, '', $file->getPathname()), DIRECTORY_SEPARATOR);
                    $classSuffix = str_replace(
                        [DIRECTORY_SEPARATOR, '.php'],
                        ['\\', ''],
                        $relativePath
                    );
                    $className = rtrim($namespacePrefix, '\\').'\\'.$classSuffix;

                    if (class_exists($className) && ! interface_exists($className) && ! trait_exists($className)) {
                        $classes[$className] = $className;
                    }
                }
            }
        }

        return array_values($classes);
    }

    /**
     * @param  array<int, string>  $left
     * @param  array<int, string>  $right
     * @return array<int, string>
     */
    private function mergeClassLists(array $left, array $right): array
    {
        $merged = [];

        foreach ([$left, $right] as $list) {
            foreach ($list as $className) {
                if (! is_string($className) || $className === '') {
                    continue;
                }
                $merged[$className] = $className;
            }
        }

        return array_values($merged);
    }

    /**
     * @param  array<int, string>  $classes
     * @return array{0: array<int, array{class: string, dependency: string, dependencyKind: string, type: string, source: string, via: string, file: string, line: int}>, 1: array<int, array{class: string, name: string, reason: string, type: string}>}
     */
    private function extractConstructorDependencyRows(array $classes): array
    {
        $dependencyRows = [];
        $unresolvedRows = [];

        foreach ($classes as $className) {
            try {
                $reflection = new ReflectionClass($className);
            } catch (Throwable) {
                continue;
            }

            $constructor = $reflection->getConstructor();
            if ($constructor === null) {
                continue;
            }

            foreach ($constructor->getParameters() as $parameter) {
                [$name, $kind] = $this->parameterDependencyResolver->resolve($parameter);
                if ($name === null) {
                    continue;
                }

                $dependencyRows[] = [
                    'class' => $className,
                    'dependency' => $name,
                    'dependencyKind' => $kind,
                    'type' => DependsOnType::ConstructorInjection->value,
                    ...$this->emptyStaticMetadata(),
                ];
            }
        }

        return [$this->uniqueRows($dependencyRows), $this->uniqueRows($unresolvedRows)];
    }

    /**
     * @return array<int, array{class: string, dependency: string, dependencyKind: string, type: string, source: string, via: string, file: string, line: int}>
     */
    private function extractStaticServiceLocationRows(): array
    {
        $paths = config('neo4j-boost.container_graph.static_scan_paths', []);
        if (! is_array($paths) || $paths === []) {
            return [];
        }

        $rows = [];
        foreach ($this->serviceLocationEdgeFinder->scanPaths($paths) as $edge) {
            $rows[] = $edge->toDependencyRow();
        }

        return $this->uniqueRows($rows);
    }

    /**
     * @return array<int, array{class: string, dependency: string, dependencyKind: string, type: string, source: string, via: string, file: string, line: int}>
     */
    private function extractStaticFacadeRows(): array
    {
        $paths = config('neo4j-boost.container_graph.static_scan_paths', []);
        if (! is_array($paths) || $paths === []) {
            return [];
        }

        $rows = [];
        foreach ($this->facadeEdgeFinder->scanPaths($paths) as $edge) {
            $rows[] = $edge->toDependencyRow();
        }

        return $this->uniqueRows($rows);
    }

    /**
     * @return array<int, array{class: string, dependency: string, dependencyKind: string, type: string, source: string, via: string, file: string, line: int, helper: string, confidence: string}>
     */
    private function extractStaticGlobalHelperRows(): array
    {
        $paths = config('neo4j-boost.container_graph.static_scan_paths', []);
        if (! is_array($paths) || $paths === []) {
            return [];
        }

        $rows = [];
        foreach ($this->globalHelperEdgeFinder->scanPaths($paths) as $edge) {
            $rows[] = $edge->toDependencyRow();
        }

        return $this->uniqueRows($rows);
    }

    /**
     * @param  array<int, array{class: string, dependency: string}>  $rows
     * @return array<int, string>
     */
    private function classNamesFromDependencyRows(array $rows): array
    {
        $classes = [];

        foreach ($rows as $row) {
            $classes[] = $row['class'];
            $classes[] = $row['dependency'];
        }

        return array_values(array_unique($classes));
    }

    /**
     * @return array{source: string, via: string, file: string, line: int}
     */
    private function emptyStaticMetadata(): array
    {
        return [
            'source' => '',
            'via' => '',
            'file' => '',
            'line' => 0,
        ];
    }

    /**
     * @return null|array{name: string, kind: string}
     */
    private function resolveConcreteDescriptor(string $abstract, mixed $concrete): ?array
    {
        if (is_string($concrete)) {
            $name = trim($concrete);

            return $name === ''
                ? null
                : ['name' => $name, 'kind' => $this->kindForTypeName($name)];
        }

        if ($concrete instanceof Closure) {
            try {
                $reflection = new ReflectionFunction($concrete);
                $static = $reflection->getStaticVariables();
                if (isset($static['concrete']) && is_string($static['concrete'])) {
                    return [
                        'name' => $static['concrete'],
                        'kind' => $this->kindForTypeName($static['concrete']),
                    ];
                }
                if (isset($static['abstract']) && is_string($static['abstract'])) {
                    return [
                        'name' => $static['abstract'],
                        'kind' => $this->kindForTypeName($static['abstract']),
                    ];
                }

                $returnType = $reflection->getReturnType();
                if ($returnType instanceof ReflectionNamedType && ! $returnType->isBuiltin()) {
                    return [
                        'name' => $returnType->getName(),
                        'kind' => $this->kindForTypeName($returnType->getName()),
                    ];
                }
            } catch (Throwable) {
                return null;
            }

            return ['name' => 'closure@'.$abstract, 'kind' => 'Closure'];
        }

        if (is_object($concrete)) {
            return ['name' => get_debug_type($concrete), 'kind' => 'Object'];
        }

        return null;
    }

    private function kindForTypeName(string $name): string
    {
        if (interface_exists($name)) {
            return 'Interface';
        }

        if (class_exists($name)) {
            return 'Class';
        }

        return 'Alias';
    }

    /**
     * @template T of array<string, mixed>
     *
     * @param  array<int, T>  $rows
     * @return array<int, T>
     */
    private function uniqueRows(array $rows): array
    {
        $seen = [];
        $result = [];

        foreach ($rows as $row) {
            $key = json_encode($row);
            if ($key === false || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $row;
        }

        return $result;
    }

    /**
     * @param  array<int, array{class: string}>  $instanceRows
     * @param  array<int, array{abstract: string, abstractKind: string, concrete: string, concreteKind: string, shared: bool, type: string}>  $bindingRows
     * @param  array<int, array{instance: string, dependency_key: string, access: string, identifier: string, identifier_kind: string, lifetime: string, injection_type: string, method: string, parameter: string, via: string, file: string, line: int}>  $dependencyChainRows
     */
    private function printCypher(ContainerGraphWriter $writer, array $instanceRows, array $bindingRows, array $dependencyChainRows): void
    {
        $this->line('');
        $this->line('Cypher templates:');
        foreach ($writer->cypherTemplates() as $label => $cypher) {
            $this->line('['.$label.']');
            $this->line($cypher);
            $this->line('');
        }

        $this->line('Sample params:');
        $this->line('- instances: '.json_encode(array_slice($instanceRows, 0, 2), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->line('- bindings: '.json_encode(array_slice($bindingRows, 0, 2), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->line('- dependency_chains: '.json_encode(array_slice($dependencyChainRows, 0, 2), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->line('');
    }
}
