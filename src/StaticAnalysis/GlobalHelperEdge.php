<?php

namespace Neo4j\LaravelBoost\StaticAnalysis;

use Neo4j\LaravelBoost\Support\Graph\DependsOnType;

/**
 * A global helper dependency discovered in PHP source (cache(), auth(), etc.).
 */
final readonly class GlobalHelperEdge
{
    /** @var list<string> */
    private const LITERAL_KEY_HELPERS = ['config', 'env'];

    public function __construct(
        public string $class,
        public string $dependency,
        public string $helper,
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
            'via' => $this->helper,
            'file' => $this->file,
            'line' => $this->line,
            'source' => DependencyEdgeSource::Static->value,
        ];
    }

    private function dependencyKind(): string
    {
        if (in_array($this->helper, self::LITERAL_KEY_HELPERS, true)) {
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
