<?php

namespace Neo4j\LaravelBoost\StaticAnalysis;

/**
 * A service-locator dependency discovered in PHP source (app / resolve / App::make).
 */
final readonly class ServiceLocationEdge
{
    public function __construct(
        public string $class,
        public string $dependency,
        public string $via,
        public string $file,
        public int $line,
    ) {}

    /**
     * @return array{
     *     class: string,
     *     dependency: string,
     *     dependencyKind: string,
     *     type: string,
     *     via: string,
     *     file: string,
     *     line: int,
     *     source: string
     * }
     */
    public function toDependencyRow(): array
    {
        return [
            'class' => $this->class,
            'dependency' => $this->dependency,
            'dependencyKind' => interface_exists($this->dependency) ? 'Interface' : 'Class',
            'type' => 'service_location',
            'via' => $this->via,
            'file' => $this->file,
            'line' => $this->line,
            'source' => DependencyEdgeSource::Static->value,
        ];
    }
}
