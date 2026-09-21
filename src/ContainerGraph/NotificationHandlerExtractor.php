<?php

namespace Neo4j\LaravelBoost\ContainerGraph;

use BackedEnum;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Channels\BroadcastChannel;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notifiable;
use ReflectionClass;
use ReflectionProperty;
use Throwable;
use UnitEnum;

/**
 * Discovers Laravel notification classes from a candidate class list and maps
 * each to Abstract (HANDLED_BY) plus USES_CHANNEL links from via().
 */
final class NotificationHandlerExtractor
{
    public function __construct(
        private MethodInjectionTargetResolver $targetResolver = new MethodInjectionTargetResolver,
    ) {}

    /**
     * @param  array<int, string>  $classes
     * @return array{
     *     notifications: array<int, array{key: string, name: string, action: string, identifier: string, identifier_kind: string, should_queue: bool, connection: string, queue: string, unique: bool}>,
     *     uses_channel: array<int, array{notification_key: string, channel_key: string, channel_kind: string, resolved_class: string, resolved_class_kind: string, order: int}>
     * }
     */
    public function extract(array $classes): array
    {
        $notifications = [];
        $usesChannel = [];
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

            if (! $this->targetResolver->isNotification($reflection)) {
                continue;
            }

            $seen[$className] = true;
            $notifications[] = [
                'key' => $className,
                'name' => $reflection->getShortName(),
                'action' => $className.'@via',
                'identifier' => $className,
                'identifier_kind' => 'Class',
                'should_queue' => $reflection->implementsInterface(ShouldQueue::class),
                'connection' => $this->stringProperty($reflection, 'connection'),
                'queue' => $this->stringProperty($reflection, 'queue'),
                'unique' => $reflection->implementsInterface(ShouldBeUnique::class)
                    || $reflection->implementsInterface(ShouldBeUniqueUntilProcessing::class),
            ];

            foreach ($this->channelsFromVia($reflection) as $order => $channel) {
                $usesChannel[] = [
                    'notification_key' => $className,
                    'channel_key' => $channel['key'],
                    'channel_kind' => $channel['kind'],
                    'resolved_class' => $channel['resolved_class'],
                    'resolved_class_kind' => $channel['resolved_class_kind'],
                    'order' => $order,
                ];
            }
        }

        return [
            'notifications' => $notifications,
            'uses_channel' => $usesChannel,
        ];
    }

    /**
     * @return list<array{key: string, kind: string, resolved_class: string, resolved_class_kind: string}>
     */
    private function channelsFromVia(ReflectionClass $reflection): array
    {
        if (! $reflection->hasMethod('via') || ! $reflection->getMethod('via')->isPublic()) {
            return [];
        }

        try {
            $instance = $reflection->newInstanceWithoutConstructor();
        } catch (Throwable) {
            return [];
        }

        $stub = new class
        {
            use Notifiable;
        };

        try {
            /** @var mixed $result */
            $result = $instance->via($stub);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($result)) {
            return [];
        }

        $channels = [];
        foreach ($result as $entry) {
            $resolved = $this->normalizeChannelEntry($entry);
            if ($resolved !== null) {
                $channels[] = $resolved;
            }
        }

        return $channels;
    }

    /**
     * @return array{key: string, kind: string, resolved_class: string, resolved_class_kind: string}|null
     */
    private function normalizeChannelEntry(mixed $entry): ?array
    {
        if ($entry instanceof BackedEnum) {
            $entry = $entry->value;
        } elseif ($entry instanceof UnitEnum) {
            return null;
        }

        if (! is_string($entry) || $entry === '') {
            return null;
        }

        $entry = ltrim($entry, '\\');

        if (class_exists($entry)) {
            return [
                'key' => $entry,
                'kind' => 'class',
                'resolved_class' => $entry,
                'resolved_class_kind' => 'Class',
            ];
        }

        $builtins = [
            'mail' => MailChannel::class,
            'database' => DatabaseChannel::class,
            'broadcast' => BroadcastChannel::class,
        ];

        if (isset($builtins[$entry])) {
            return [
                'key' => $entry,
                'kind' => 'builtin',
                'resolved_class' => $builtins[$entry],
                'resolved_class_kind' => 'Class',
            ];
        }

        return [
            'key' => $entry,
            'kind' => 'named',
            'resolved_class' => '',
            'resolved_class_kind' => '',
        ];
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
