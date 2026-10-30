<?php

namespace Neo4j\LaravelBoost\ContainerGraph;

use Closure;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\QueuedClosure;
use Illuminate\Support\Str;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use Throwable;

/**
 * Discovers Eloquent model lifecycle wiring beyond ordinary EventDispatcher
 * listeners: observers (live eloquent.* listeners + #[ObservedBy]) and
 * $dispatchesEvents maps on model classes.
 *
 * Closures are skipped (same stance as EventListenerExtractor).
 */
final class EloquentModelEventExtractor
{
    private const EVENT_PATTERN = '/^eloquent\.([A-Za-z]+):\s*(.+)$/';

    /**
     * @param  array<int, string>  $classes
     * @return array<int, array{
     *     key: string,
     *     name: string,
     *     lifecycle: string,
     *     source: string,
     *     model: string,
     *     model_kind: string,
     *     identifier: string,
     *     identifier_kind: string,
     *     action: string,
     *     event_class: string,
     *     event_class_kind: string
     * }>
     */
    public function extract(array $classes = [], ?object $events = null): array
    {
        $events ??= app('events');
        $byKey = [];
        $modelsToScan = [];

        foreach ($this->fromDispatcher($events) as $row) {
            $this->mergeRow($byKey, $row);
            $modelsToScan[$row['model']] = true;
        }

        foreach ($classes as $className) {
            if (is_string($className) && $className !== '') {
                $modelsToScan[$className] = true;
            }
        }

        foreach (array_keys($modelsToScan) as $className) {
            try {
                $reflection = new ReflectionClass($className);
            } catch (Throwable) {
                continue;
            }

            if (! $this->isEloquentModel($reflection)) {
                continue;
            }

            foreach ($this->fromObservedBy($reflection) as $row) {
                $this->mergeRow($byKey, $row);
            }

            foreach ($this->fromDispatchesEvents($reflection) as $row) {
                $this->mergeRow($byKey, $row);
            }
        }

        return array_values($byKey);
    }

    /**
     * @return array<int, array{
     *     key: string,
     *     name: string,
     *     lifecycle: string,
     *     source: string,
     *     model: string,
     *     model_kind: string,
     *     identifier: string,
     *     identifier_kind: string,
     *     action: string,
     *     event_class: string,
     *     event_class_kind: string
     * }>
     */
    private function fromDispatcher(object $events): array
    {
        if (! method_exists($events, 'getRawListeners')) {
            return [];
        }

        /** @var array<string, mixed> $rawListeners */
        $rawListeners = $events->getRawListeners();
        $rows = [];
        $seen = [];

        foreach ($rawListeners as $eventName => $listeners) {
            if (! is_string($eventName) || ! preg_match(self::EVENT_PATTERN, $eventName, $matches)) {
                continue;
            }

            $lifecycle = $matches[1];
            $model = ltrim($matches[2], '\\');
            if ($model === '') {
                continue;
            }

            foreach ((array) $listeners as $listener) {
                $resolved = $this->resolveListener($listener);
                if ($resolved === null) {
                    continue;
                }

                [$identifier, $method, $action] = $resolved;
                $dedupeKey = $eventName."\0".$identifier."\0".$method;
                if (isset($seen[$dedupeKey])) {
                    continue;
                }
                $seen[$dedupeKey] = true;

                $rows[] = $this->observerRow($model, $lifecycle, $identifier, $action, 'observer');
            }
        }

        return $rows;
    }

    /**
     * @return array<int, array{
     *     key: string,
     *     name: string,
     *     lifecycle: string,
     *     source: string,
     *     model: string,
     *     model_kind: string,
     *     identifier: string,
     *     identifier_kind: string,
     *     action: string,
     *     event_class: string,
     *     event_class_kind: string
     * }>
     */
    private function fromObservedBy(ReflectionClass $reflection): array
    {
        $model = $reflection->getName();
        $rows = [];

        foreach ($reflection->getAttributes(ObservedBy::class) as $attribute) {
            foreach ($this->observerClassesFromAttribute($attribute) as $observer) {
                foreach ($this->observableMethods($observer) as $lifecycle) {
                    $rows[] = $this->observerRow(
                        $model,
                        $lifecycle,
                        $observer,
                        $observer.'@'.$lifecycle,
                        'observed_by',
                    );
                }
            }
        }

        return $rows;
    }

    /**
     * @return array<int, array{
     *     key: string,
     *     name: string,
     *     lifecycle: string,
     *     source: string,
     *     model: string,
     *     model_kind: string,
     *     identifier: string,
     *     identifier_kind: string,
     *     action: string,
     *     event_class: string,
     *     event_class_kind: string
     * }>
     */
    private function fromDispatchesEvents(ReflectionClass $reflection): array
    {
        $map = $this->dispatchesEventsMap($reflection);
        if ($map === []) {
            return [];
        }

        $model = $reflection->getName();
        $rows = [];

        foreach ($map as $lifecycle => $eventClass) {
            if (! is_string($lifecycle) || $lifecycle === '' || ! is_string($eventClass) || $eventClass === '') {
                continue;
            }

            $eventClass = ltrim($eventClass, '\\');
            $rows[] = [
                'key' => $this->eventKey($model, $lifecycle),
                'name' => $this->eventDisplayName($model, $lifecycle),
                'lifecycle' => $lifecycle,
                'source' => 'dispatches_events',
                'model' => $model,
                'model_kind' => 'Class',
                'identifier' => '',
                'identifier_kind' => '',
                'action' => '',
                'event_class' => $eventClass,
                'event_class_kind' => $this->kindForTypeName($eventClass),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, array{
     *     key: string,
     *     name: string,
     *     lifecycle: string,
     *     source: string,
     *     model: string,
     *     model_kind: string,
     *     identifier: string,
     *     identifier_kind: string,
     *     action: string,
     *     event_class: string,
     *     event_class_kind: string
     * }>  $byKey
     * @param  array{
     *     key: string,
     *     name: string,
     *     lifecycle: string,
     *     source: string,
     *     model: string,
     *     model_kind: string,
     *     identifier: string,
     *     identifier_kind: string,
     *     action: string,
     *     event_class: string,
     *     event_class_kind: string
     * }  $row
     */
    private function mergeRow(array &$byKey, array $row): void
    {
        $key = $row['key']."\0".$row['identifier']."\0".$row['action']."\0".$row['event_class'];
        if (isset($byKey[$key])) {
            return;
        }

        $byKey[$key] = $row;
    }

    /**
     * @return array{
     *     key: string,
     *     name: string,
     *     lifecycle: string,
     *     source: string,
     *     model: string,
     *     model_kind: string,
     *     identifier: string,
     *     identifier_kind: string,
     *     action: string,
     *     event_class: string,
     *     event_class_kind: string
     * }
     */
    private function observerRow(
        string $model,
        string $lifecycle,
        string $identifier,
        string $action,
        string $source,
    ): array {
        return [
            'key' => $this->eventKey($model, $lifecycle),
            'name' => $this->eventDisplayName($model, $lifecycle),
            'lifecycle' => $lifecycle,
            'source' => $source,
            'model' => $model,
            'model_kind' => 'Class',
            'identifier' => $identifier,
            'identifier_kind' => $this->kindForTypeName($identifier),
            'action' => $action,
            'event_class' => '',
            'event_class_kind' => '',
        ];
    }

    /**
     * @return list<string>
     */
    private function observerClassesFromAttribute(ReflectionAttribute $attribute): array
    {
        $instance = $attribute->newInstance();
        $classes = $instance->classes ?? null;
        if (is_string($classes) && $classes !== '') {
            $classes = [$classes];
        }

        if (! is_array($classes)) {
            return [];
        }

        $resolved = [];
        foreach ($classes as $class) {
            if (is_string($class) && $class !== '') {
                $resolved[] = ltrim($class, '\\');
            }
        }

        return $resolved;
    }

    /**
     * @return list<string>
     */
    private function observableMethods(string $observer): array
    {
        try {
            $reflection = new ReflectionClass($observer);
        } catch (ReflectionException) {
            return [];
        }

        $methods = [];
        foreach ($this->defaultObservableEvents() as $event) {
            if ($reflection->hasMethod($event) && $reflection->getMethod($event)->isPublic()) {
                $methods[] = $event;
            }
        }

        return $methods;
    }

    /**
     * @return array<string, string>
     */
    private function dispatchesEventsMap(ReflectionClass $reflection): array
    {
        $defaults = $reflection->getDefaultProperties();
        $map = $defaults['dispatchesEvents'] ?? null;

        return is_array($map) ? $map : [];
    }

    /**
     * @return null|array{0: string, 1: string, 2: string}
     */
    private function resolveListener(mixed $listener): ?array
    {
        if ($listener instanceof Closure || $listener instanceof QueuedClosure) {
            return null;
        }

        if (is_array($listener)) {
            if (count($listener) !== 2) {
                return null;
            }

            [$class, $method] = $listener;
            if (! is_string($class) || $class === '' || ! is_string($method) || $method === '') {
                return null;
            }

            return [ltrim($class, '\\'), $method, ltrim($class, '\\').'@'.$method];
        }

        if (! is_string($listener) || $listener === '') {
            return null;
        }

        [$class, $method] = Str::parseCallback($listener, 'handle');
        if (! is_string($class) || $class === '') {
            return null;
        }

        $class = ltrim($class, '\\');
        $method = is_string($method) && $method !== '' ? $method : 'handle';

        return [$class, $method, $class.'@'.$method];
    }

    private function isEloquentModel(ReflectionClass $reflection): bool
    {
        if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isTrait()) {
            return false;
        }

        return $reflection->isSubclassOf(Model::class);
    }

    /**
     * @return list<string>
     */
    private function defaultObservableEvents(): array
    {
        return [
            'retrieved', 'creating', 'created', 'updating', 'updated',
            'saving', 'saved', 'restoring', 'restored', 'replicating',
            'trashed', 'deleting', 'deleted', 'forceDeleting', 'forceDeleted',
        ];
    }

    private function eventKey(string $model, string $lifecycle): string
    {
        return 'eloquent.'.$lifecycle.': '.$model;
    }

    private function eventDisplayName(string $model, string $lifecycle): string
    {
        $parts = explode('\\', $model);
        $short = (string) end($parts);

        return $short.' '.$lifecycle;
    }

    private function kindForTypeName(string $typeName): string
    {
        if (interface_exists($typeName)) {
            return 'Interface';
        }

        if (class_exists($typeName)) {
            return 'Class';
        }

        return 'Alias';
    }
}
