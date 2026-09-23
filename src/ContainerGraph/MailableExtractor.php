<?php

namespace Neo4j\LaravelBoost\ContainerGraph;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

/**
 * Discovers Laravel mailable classes from a candidate class list and maps each
 * to a container Abstract for HANDLED_BY edges, with optional USES_MAILER /
 * USES_CONNECTION when defaults are declared.
 *
 * Queued mailables (ShouldQueue) are exported as Mailables, not Jobs.
 */
final class MailableExtractor
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
     *     mailer: string,
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

            if (! $this->targetResolver->isMailable($reflection)) {
                continue;
            }

            $method = $this->targetResolver->resolveMailableHandlerMethod($reflection);

            $seen[$className] = true;
            $rows[] = [
                'key' => $className,
                'name' => $reflection->getShortName(),
                'action' => $method === '' ? $className : $className.'@'.$method,
                'identifier' => $className,
                'identifier_kind' => 'Class',
                'should_queue' => $reflection->implementsInterface(ShouldQueue::class),
                'mailer' => $this->stringProperty($reflection, 'mailer'),
                'connection' => $this->stringProperty($reflection, 'connection'),
                'queue' => $this->stringProperty($reflection, 'queue'),
                'unique' => $reflection->implementsInterface(ShouldBeUnique::class)
                    || $reflection->implementsInterface(ShouldBeUniqueUntilProcessing::class),
            ];
        }

        return $rows;
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
