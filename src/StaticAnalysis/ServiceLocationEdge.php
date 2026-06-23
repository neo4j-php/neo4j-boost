<?php

namespace Neo4j\LaravelBoost\StaticAnalysis;

/**
 * A service-locator dependency discovered in PHP source (app / resolve / App::make / Application::make / $app->make).
 */
final readonly class ServiceLocationEdge
{
    public function __construct(
        public string $class,
        public string $dependency,
        public string $via,
        public string $file,
        public int $line,
        public bool $resolved = true,
        public ?string $reason = null,
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
     *     reason?: string
     * }
     */
    public function toDependencyRow(): array
    {
        $row = [
            'class' => $this->class,
            'dependency' => $this->dependency,
            'dependencyKind' => $this->resolved
                ? (interface_exists($this->dependency) ? 'Interface' : 'Class')
                : 'Unresolved',
            'type' => 'service_location',
            'via' => $this->via,
            'file' => $this->file,
            'line' => $this->line,
            'source' => DependencyEdgeSource::Static->value,
        ];

        if (! $this->resolved) {
            $row['reason'] = $this->reason ?? ServiceLocationCallDetector::UNRESOLVED_REASON;
        }

        return $row;
    }
}
