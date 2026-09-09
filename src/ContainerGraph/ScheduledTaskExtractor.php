<?php

namespace Neo4j\LaravelBoost\ContainerGraph;

use Closure;
use DateTimeZone;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use ReflectionClass;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

/**
 * Discovers Laravel scheduler / cron entries from the live Schedule and maps
 * resolvable command, job, and callable targets to container Abstracts for
 * HANDLED_BY edges.
 *
 * Closure callbacks and unresolved shell/exec targets are still exported as
 * ScheduledTask nodes, but without a HANDLED_BY identifier.
 */
final class ScheduledTaskExtractor
{
    /**
     * @return array<int, array{
     *     key: string,
     *     name: string,
     *     expression: string,
     *     command: string,
     *     description: string,
     *     timezone: string,
     *     kind: string,
     *     without_overlapping: bool,
     *     on_one_server: bool,
     *     run_in_background: bool,
     *     even_in_maintenance_mode: bool,
     *     action: string,
     *     identifier: string,
     *     identifier_kind: string
     * }>
     */
    public function extract(?Schedule $schedule = null): array
    {
        try {
            $schedule ??= app(Schedule::class);
        } catch (Throwable) {
            return [];
        }

        if (! $schedule instanceof Schedule) {
            return [];
        }

        $rows = [];
        $seen = [];

        foreach ($schedule->events() as $event) {
            if (! $event instanceof Event) {
                continue;
            }

            $row = $this->mapEvent($event);
            if (isset($seen[$row['key']])) {
                continue;
            }
            $seen[$row['key']] = true;
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return array{
     *     key: string,
     *     name: string,
     *     expression: string,
     *     command: string,
     *     description: string,
     *     timezone: string,
     *     kind: string,
     *     without_overlapping: bool,
     *     on_one_server: bool,
     *     run_in_background: bool,
     *     even_in_maintenance_mode: bool,
     *     action: string,
     *     identifier: string,
     *     identifier_kind: string
     * }
     */
    private function mapEvent(Event $event): array
    {
        $expression = (string) $event->getExpression();
        $description = is_string($event->description ?? null) ? (string) $event->description : '';
        $command = is_string($event->command) ? (string) $event->command : '';
        $summary = (string) $event->getSummaryForDisplay();
        $kind = $this->resolveKind($event, $description, $command);
        [$identifier, $action] = $this->resolveHandler($event, $kind, $description, $command);

        $name = $this->displayName($description, $summary, $identifier, $kind);
        $key = $this->taskKey($expression, $summary, $description, $command, $identifier);

        return [
            'key' => $key,
            'name' => $name,
            'expression' => $expression,
            'command' => $command !== '' ? Event::normalizeCommand($command) : $summary,
            'description' => $description,
            'timezone' => $this->timezoneString($event->timezone),
            'kind' => $kind,
            'without_overlapping' => (bool) $event->withoutOverlapping,
            'on_one_server' => (bool) $event->onOneServer,
            'run_in_background' => (bool) $event->runInBackground,
            'even_in_maintenance_mode' => (bool) $event->evenInMaintenanceMode,
            'action' => $action,
            'identifier' => $identifier,
            'identifier_kind' => $identifier === '' ? '' : $this->identifierKind($identifier),
        ];
    }

    private function resolveKind(Event $event, string $description, string $command): string
    {
        if ($event instanceof CallbackEvent) {
            if ($description !== '' && (class_exists($description) || interface_exists($description))) {
                return 'job';
            }

            return 'callback';
        }

        if ($command !== '' && ! str_contains($command, 'artisan')) {
            return 'exec';
        }

        return 'command';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveHandler(Event $event, string $kind, string $description, string $command): array
    {
        if ($kind === 'job' && $description !== '') {
            $method = $this->resolveClassHandlerMethod($description);

            return [$description, $description.'@'.$method];
        }

        if ($event instanceof CallbackEvent) {
            return $this->resolveCallbackHandler($event);
        }

        if ($kind === 'command' && $command !== '') {
            $commandClass = $this->resolveArtisanCommandClass($command);
            if ($commandClass !== null) {
                $method = $this->resolveClassHandlerMethod($commandClass);

                return [$commandClass, $commandClass.'@'.$method];
            }
        }

        return ['', ''];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveCallbackHandler(CallbackEvent $event): array
    {
        try {
            $callback = (new ReflectionClass($event))->getProperty('callback')->getValue($event);
        } catch (Throwable) {
            return ['', ''];
        }

        if ($callback instanceof Closure) {
            return ['', ''];
        }

        if (is_string($callback) && $callback !== '') {
            if (str_contains($callback, '@')) {
                [$class, $method] = explode('@', $callback, 2);

                return [$class, $class.'@'.($method !== '' ? $method : 'handle')];
            }

            if (class_exists($callback)) {
                $method = $this->resolveClassHandlerMethod($callback);

                return [$callback, $callback.'@'.$method];
            }

            return ['', ''];
        }

        if (is_array($callback) && count($callback) === 2) {
            [$target, $method] = $callback;
            $class = is_object($target) ? $target::class : (is_string($target) ? $target : null);
            if (! is_string($class) || $class === '' || ! is_string($method) || $method === '') {
                return ['', ''];
            }

            return [$class, $class.'@'.$method];
        }

        if (is_object($callback)) {
            $class = $callback::class;
            $method = method_exists($callback, '__invoke') ? '__invoke' : 'handle';

            return [$class, $class.'@'.$method];
        }

        return ['', ''];
    }

    private function resolveArtisanCommandClass(string $command): ?string
    {
        $name = $this->parseArtisanCommandName($command);
        if ($name === null) {
            return null;
        }

        try {
            $commands = Artisan::all();
        } catch (Throwable) {
            return null;
        }

        $resolved = $commands[$name] ?? null;
        if (! $resolved instanceof SymfonyCommand) {
            return null;
        }

        return $resolved::class;
    }

    private function parseArtisanCommandName(string $command): ?string
    {
        $normalized = Event::normalizeCommand($command);

        if (preg_match('/(?:^|\\s)artisan\\s+(\\S+)/i', $normalized, $matches) === 1) {
            return $matches[1];
        }

        // Bare command name already (unusual, but tolerate it).
        if (! str_contains($normalized, ' ') && ! str_contains($normalized, DIRECTORY_SEPARATOR)) {
            return $normalized;
        }

        return null;
    }

    private function resolveClassHandlerMethod(string $class): string
    {
        try {
            $reflection = new ReflectionClass($class);
        } catch (Throwable) {
            return 'handle';
        }

        if ($reflection->hasMethod('handle') && $reflection->getMethod('handle')->isPublic()) {
            return 'handle';
        }

        if ($reflection->hasMethod('__invoke') && $reflection->getMethod('__invoke')->isPublic()) {
            return '__invoke';
        }

        return 'handle';
    }

    private function displayName(string $description, string $summary, string $identifier, string $kind): string
    {
        if ($description !== '') {
            if (str_contains($description, '\\')) {
                $parts = explode('\\', $description);

                return (string) end($parts);
            }

            return $description;
        }

        if ($identifier !== '' && str_contains($identifier, '\\')) {
            $parts = explode('\\', $identifier);

            return (string) end($parts);
        }

        if ($summary !== '' && $summary !== 'Callback') {
            return $summary;
        }

        return $kind;
    }

    private function taskKey(
        string $expression,
        string $summary,
        string $description,
        string $command,
        string $identifier,
    ): string {
        $payload = $expression."\0".$summary."\0".$description."\0".$command."\0".$identifier;

        return hash('sha1', $payload);
    }

    private function timezoneString(mixed $timezone): string
    {
        if ($timezone instanceof DateTimeZone) {
            return $timezone->getName();
        }

        return is_string($timezone) ? $timezone : '';
    }

    private function identifierKind(string $identifier): string
    {
        if (interface_exists($identifier)) {
            return 'Interface';
        }

        if (class_exists($identifier)) {
            return 'Class';
        }

        return 'Alias';
    }
}
