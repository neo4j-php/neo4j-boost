<?php

namespace Neo4j\LaravelBoost\ContainerGraph;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Neo4j\LaravelBoost\Support\Graph\TypeNameKind;
use ReflectionClass;

final class ContextualBindingExtractor
{
    public function __construct(
        private ContextualGiveResolver $giveResolver,
    ) {}

    /**
     * @return array{
     *     rows: array<int, array{when: string, when_kind: string, needs: string, needs_kind: string, give: string, give_kind: string, reason: string}>,
     *     when_classes: array<int, string>
     * }
     */
    public function extract(Application $app): array
    {
        if (! $app instanceof Container) {
            return ['rows' => [], 'when_classes' => []];
        }

        /** @var array<string, array<string, mixed>> $contextual */
        $contextual = $app->contextual;

        $rows = [];
        $whenClasses = [];

        foreach ($contextual as $when => $needsMap) {
            if (! is_string($when) || $when === '' || ! is_array($needsMap)) {
                continue;
            }

            $whenClasses[$when] = $when;

            foreach ($needsMap as $needs => $implementation) {
                if (! is_string($needs) || $needs === '') {
                    continue;
                }

                $normalizedNeeds = $this->normalizeNeeds($app, $needs);

                foreach ($this->giveResolver->resolve($implementation, $normalizedNeeds) as $give) {
                    $rows[] = [
                        'when' => $when,
                        'when_kind' => TypeNameKind::for($when),
                        'needs' => $normalizedNeeds,
                        'needs_kind' => TypeNameKind::for($normalizedNeeds),
                        'give' => $give['name'],
                        'give_kind' => $give['kind'],
                        'reason' => $give['reason'],
                    ];
                }
            }
        }

        return [
            'rows' => $rows,
            'when_classes' => array_values($whenClasses),
        ];
    }

    private function normalizeNeeds(Container $container, string $needs): string
    {
        if (interface_exists($needs) || class_exists($needs)) {
            return $needs;
        }

        $reflection = new ReflectionClass($container);

        if ($reflection->hasProperty('abstractAliases')) {
            $property = $reflection->getProperty('abstractAliases');
            $property->setAccessible(true);
            /** @var array<string, array<int, string>> $abstractAliases */
            $abstractAliases = $property->getValue($container);

            foreach ($abstractAliases as $abstract => $aliases) {
                if (in_array($needs, $aliases, true)) {
                    return $abstract;
                }
            }
        }

        if ($reflection->hasProperty('aliases')) {
            $property = $reflection->getProperty('aliases');
            $property->setAccessible(true);
            /** @var array<string, string> $aliases */
            $aliases = $property->getValue($container);

            foreach ($aliases as $abstract => $alias) {
                if ($alias === $needs) {
                    return $abstract;
                }
            }
        }

        return $needs;
    }
}
