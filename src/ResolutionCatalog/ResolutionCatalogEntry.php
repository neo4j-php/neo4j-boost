<?php

namespace Neo4j\LaravelBoost\ResolutionCatalog;

use Neo4j\LaravelBoost\Support\Graph\BindsToType;

/**
 * Maps a facade or global helper to the abstract binding key the container resolves.
 */
final readonly class ResolutionCatalogEntry
{
    public function __construct(
        public string $identifier,
        public ResolutionCatalogKind $kind,
        public string $abstract,
        public BindsToType $bindsToType,
        public ResolutionCatalogSource $source,
        public ?string $bindingKey = null,
        public ?string $facadeClass = null,
    ) {}

    /**
     * @return array{
     *     identifier: string,
     *     kind: string,
     *     abstract: string,
     *     binding_key: null|string,
     *     facade_class: null|string,
     *     binds_to_type: string,
     *     source: string
     * }
     */
    public function toArray(): array
    {
        return [
            'identifier' => $this->identifier,
            'kind' => $this->kind->value,
            'abstract' => $this->abstract,
            'binding_key' => $this->bindingKey,
            'facade_class' => $this->facadeClass,
            'binds_to_type' => $this->bindsToType->value,
            'source' => $this->source->value,
        ];
    }
}
