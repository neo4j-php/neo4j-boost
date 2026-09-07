<?php

namespace Neo4j\LaravelBoost\ContainerGraph;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

/**
 * Discovers Laravel job classes from a candidate class list and maps each
 * to a container Abstract for HANDLED_BY edges.
 *
 * Queued listeners (*Listener / \Listeners\) are excluded even when they
 * implement ShouldQueue.
 */
final class JobHandlerExtractor
{
    public function __construct(
        private MethodInjectionTargetResolver $targetResolver = new MethodInjectionTargetResolver,
    ) {}

    /**
     * @param  array<int, string>  $classes
     * @return array<int, array{
     *     key: string,
     *     name: string,
     *     action: string,
     *     identifier: string,
     *     identifier_kind: string,
     *     should_queue: bool,
     *     connection: string,
     *     queue: string,
     *     unique: bool
     * }>
     */
    public function extract(array $classes): array
    {
        $rows = [];
        $seen = [];

        foreach ($classes as $className) {
            if (! is_string($className) || $className === '' || isset($seen[$className])) {
                continue;
            }

            try {
                $reflection = new ReflectionClass($className);
            } catch (Throwable) {
                continue;
            }

            if (! $this->targetResolver->isJob($reflection)) {
                continue;
            }

            $method = $this->resolveHandlerMethod($reflection);
            if ($method === null) {
                continue;
            }

            $seen[$className] = true;
            $rows[] = [
                'key' => $className,
                'name' => $reflection->getShortName(),
                'action' => $className.'@'.$method,
                'identifier' => $className,
                'identifier_kind' => 'Class',
                'should_queue' => $reflection->implementsInterface(ShouldQueue::class),
                'connection' => $this->stringProperty($reflection, 'connection'),
                'queue' => $this->stringProperty($reflection, 'queue'),
                'unique' => $reflection->implementsInterface(ShouldBeUnique::class)
                    || $reflection->implementsInterface(ShouldBeUniqueUntilProcessing::class),
            ];
        }

        return $rows;
    }

    private function resolveHandlerMethod(ReflectionClass $reflection): ?string
    {
        if ($reflection->hasMethod('handle') && $reflection->getMethod('handle')->isPublic()) {
            return 'handle';
        }

        if ($reflection->hasMethod('__invoke') && $reflection->getMethod('__invoke')->isPublic()) {
            return '__invoke';
        }

        return null;
    }

    private function stringProperty(ReflectionClass $reflection, string $property): string
    {
        if (! $reflection->hasProperty($property)) {
            return '';
        }

        $prop = $reflection->getProperty($property);
        if (! $prop instanceof ReflectionProperty || ! $prop->isPublic()) {
            return '';
        }

        $defaults = $reflection->getDefaultProperties();
        $value = $defaults[$property] ?? null;

        return is_string($value) ? $value : '';
    }
}
