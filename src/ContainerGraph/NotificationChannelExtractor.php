<?php

namespace Neo4j\LaravelBoost\ContainerGraph;

use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Channels\BroadcastChannel;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Manager;
use ReflectionProperty;
use Throwable;

/**
 * Discovers notification channel drivers from ChannelManager:
 * built-in mail/database/broadcast plus Notification::extend() creators.
 */
final class NotificationChannelExtractor
{
    /** @var array<string, class-string> */
    private const BUILTIN_CHANNELS = [
        'mail' => MailChannel::class,
        'database' => DatabaseChannel::class,
        'broadcast' => BroadcastChannel::class,
    ];

    /**
     * @return array<int, array{key: string, name: string, kind: string, resolved_class: string, resolved_class_kind: string, is_default: bool}>
     */
    public function extract(?ChannelManager $manager = null): array
    {
        $manager ??= $this->resolveManager();
        $default = $manager instanceof ChannelManager ? (string) $manager->deliversVia() : 'mail';

        $rows = [];
        $seen = [];

        foreach (self::BUILTIN_CHANNELS as $key => $class) {
            $seen[$key] = true;
            $rows[] = [
                'key' => $key,
                'name' => $key,
                'kind' => 'builtin',
                'resolved_class' => $class,
                'resolved_class_kind' => 'Class',
                'is_default' => $key === $default,
            ];
        }

        if ($manager instanceof ChannelManager) {
            foreach ($this->customCreatorKeys($manager) as $key) {
                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $rows[] = [
                    'key' => $key,
                    'name' => $key,
                    'kind' => 'extended',
                    'resolved_class' => '',
                    'resolved_class_kind' => '',
                    'is_default' => $key === $default,
                ];
            }
        }

        return $rows;
    }

    private function resolveManager(): ?ChannelManager
    {
        try {
            $manager = app(ChannelManager::class);
        } catch (Throwable) {
            return null;
        }

        return $manager instanceof ChannelManager ? $manager : null;
    }

    /**
     * @return list<string>
     */
    private function customCreatorKeys(ChannelManager $manager): array
    {
        try {
            $property = new ReflectionProperty(Manager::class, 'customCreators');
            /** @var mixed $value */
            $value = $property->getValue($manager);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($value)) {
            return [];
        }

        $keys = [];
        foreach (array_keys($value) as $key) {
            if (is_string($key) && $key !== '') {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
