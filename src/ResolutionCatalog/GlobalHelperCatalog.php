<?php

namespace Neo4j\LaravelBoost\ResolutionCatalog;

/**
 * Maps Laravel global helpers to container binding keys and resolved abstracts.
 */
final class GlobalHelperCatalog
{
    /** @var array<string, string> */
    private const HIGH_CONFIDENCE_BINDING_KEYS = [
        'cache' => 'cache',
        'auth' => 'auth',
        'view' => 'view',
        'response' => 'Illuminate\Contracts\Routing\ResponseFactory',
        'redirect' => 'redirect',
        'route' => 'router',
        'event' => 'events',
        'dispatch' => 'Illuminate\Contracts\Bus\Dispatcher',
        'logger' => 'log',
        'session' => 'session',
    ];

    /** @var list<string> */
    private const LOW_CONFIDENCE_HELPERS = [
        'config',
        'env',
    ];

    public function __construct(
        private ContainerBindingAbstractResolver $abstractResolver,
    ) {}

    /**
     * @return list<string>
     */
    public function highConfidenceHelpers(): array
    {
        return array_keys(self::HIGH_CONFIDENCE_BINDING_KEYS);
    }

    /**
     * @return list<string>
     */
    public function lowConfidenceHelpers(): array
    {
        return self::LOW_CONFIDENCE_HELPERS;
    }

    public function isTrackedHelper(string $helper): bool
    {
        return isset(self::HIGH_CONFIDENCE_BINDING_KEYS[$helper])
            || in_array($helper, self::LOW_CONFIDENCE_HELPERS, true);
    }

    public function isLowConfidence(string $helper): bool
    {
        return in_array($helper, self::LOW_CONFIDENCE_HELPERS, true);
    }

    /**
     * @return array{
     *     helper: string,
     *     binding_key: string,
     *     abstract: string,
     *     confidence: GlobalHelperConfidence
     * }
     */
    public function resolve(string $helper, ?string $literalKey = null): ?array
    {
        if (! $this->isTrackedHelper($helper)) {
            return null;
        }

        if ($this->isLowConfidence($helper)) {
            $bindingKey = $literalKey !== null && $literalKey !== ''
                ? $helper.'.'.$literalKey
                : $helper;

            return [
                'helper' => $helper,
                'binding_key' => $bindingKey,
                'abstract' => $bindingKey,
                'confidence' => GlobalHelperConfidence::Low,
            ];
        }

        $bindingKey = self::HIGH_CONFIDENCE_BINDING_KEYS[$helper];

        return [
            'helper' => $helper,
            'binding_key' => $bindingKey,
            'abstract' => $this->abstractResolver->resolveForBindingKey($bindingKey),
            'confidence' => GlobalHelperConfidence::High,
        ];
    }
}
