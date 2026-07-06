<?php

namespace Neo4j\LaravelBoost\StaticAnalysis;

use Neo4j\LaravelBoost\Support\Graph\DependsOnType;

/**
 * A facade dependency discovered in PHP source (Cache::get, custom Facade::method, etc.).
 */
final readonly class FacadeEdge
{
    public function __construct(
        public string $class,
        public string $dependency,
        public string $facadeClass,
        public string $method,
        public string $file,
        public int $line,
        public string $catalogSource = '',
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
     *     source: string,
     *     catalog_source: string
     * }
     */
    public function toDependencyRow(): array
    {
        return [
            'class' => $this->class,
            'dependency' => $this->dependency,
            'dependencyKind' => $this->dependencyKind(),
            'type' => DependsOnType::Facade->value,
            'via' => $this->facadeClass.'::'.$this->method,
            'file' => $this->file,
            'line' => $this->line,
            'source' => DependencyEdgeSource::Static->value,
            'catalog_source' => $this->catalogSource,
        ];
    }

    private function dependencyKind(): string
    {
        if (interface_exists($this->dependency)) {
            return 'Interface';
        }

        if (class_exists($this->dependency)) {
            return 'Class';
        }

        return 'Alias';
    }
}
