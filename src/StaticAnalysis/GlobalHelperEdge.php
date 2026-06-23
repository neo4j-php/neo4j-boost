<?php

namespace Neo4j\LaravelBoost\StaticAnalysis;

use Neo4j\LaravelBoost\ResolutionCatalog\GlobalHelperConfidence;
use Neo4j\LaravelBoost\Support\Graph\DependsOnType;

/**
 * A global helper dependency discovered in PHP source (cache(), auth(), etc.).
 */
final readonly class GlobalHelperEdge
{
    public function __construct(
        public string $class,
        public string $dependency,
        public string $helper,
        public GlobalHelperConfidence $confidence,
        public string $file,
        public int $line,
    ) {}

    /**
     * @return array{
     *     class: string,
     *     dependency: string,
     *     dependencyKind: string,
     *     type: string,
     *     helper: string,
     *     confidence: string,
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
            'dependencyKind' => $this->dependencyKind(),
            'type' => DependsOnType::GlobalHelper->value,
            'helper' => $this->helper,
            'confidence' => $this->confidence->value,
            'via' => $this->helper,
            'file' => $this->file,
            'line' => $this->line,
            'source' => DependencyEdgeSource::Static->value,
        ];
    }

    private function dependencyKind(): string
    {
        if ($this->confidence === GlobalHelperConfidence::Low) {
            return 'Alias';
        }

        if (interface_exists($this->dependency)) {
            return 'Interface';
        }

        if (class_exists($this->dependency)) {
            return 'Class';
        }

        return 'Alias';
    }
}
