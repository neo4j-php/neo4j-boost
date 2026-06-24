<?php

namespace Neo4j\LaravelBoost\ResolutionCatalog;

/**
 * Maps Laravel global helpers to container binding keys and resolved abstracts.
 */
final class GlobalHelperCatalog
{
    /** @var array<string, string> */
    private const BINDING_KEYS = [
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
    private const LITERAL_KEY_HELPERS = [
        'config',
        'env',
    ];

    public function __construct(
        private ContainerBindingAbstractResolver $abstractResolver,
    ) {}

    public function isTrackedHelper(string $helper): bool
    {
        return isset(self::BINDING_KEYS[$helper])
            || in_array($helper, self::LITERAL_KEY_HELPERS, true);
    }

    public function usesLiteralKey(string $helper): bool
    {
        return in_array($helper, self::LITERAL_KEY_HELPERS, true);
    }

    /**
     * @return array{
     *     helper: string,
     *     binding_key: string,
     *     abstract: string
     * }
     */
    public function resolve(string $helper, ?string $literalKey = null): ?array
    {
        if (! $this->isTrackedHelper($helper)) {
            return null;
        }

        if ($this->usesLiteralKey($helper)) {
            $bindingKey = $literalKey !== null && $literalKey !== ''
                ? $helper.'.'.$literalKey
                : $helper;

            return [
                'helper' => $helper,
                'binding_key' => $bindingKey,
                'abstract' => $bindingKey,
            ];
        }

        $bindingKey = self::BINDING_KEYS[$helper];

        return [
            'helper' => $helper,
            'binding_key' => $bindingKey,
            'abstract' => $this->abstractResolver->resolveForBindingKey($bindingKey),
        ];
    }
}
