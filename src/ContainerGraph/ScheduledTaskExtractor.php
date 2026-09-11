<?php

namespace Neo4j\LaravelBoost\ContainerGraph;

use Closure;
use DateTimeZone;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use ReflectionClass;
use ReflectionFunction;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

/**
 * Discovers Laravel scheduler / cron entries from the live Schedule and maps
 * resolvable command, job, and callable targets to container Abstracts for
 * HANDLED_BY edges.
 *
 * Closures and unresolved shell/exec targets are still exported as
 * ScheduledTask nodes, but without a HANDLED_BY identifier.
 *
 * Schedule::job() always wraps a Closure that binds `$job`; that binding is
 * preferred over description()/name() so human labels and displayName() do not
 * break HANDLED_BY resolution. call()->name(SomeClass::class) uses the real
 * callable for HANDLED_BY — the name is display-only.
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

        if ($event instanceof CallbackEvent) {
            [$identifier, $action, $kind] = $this->resolveCallbackEvent($event);
        } elseif ($command !== '' && ! str_contains($command, 'artisan')) {
            $identifier = '';
            $action = '';
            $kind = 'exec';
        } else {
            $kind = 'command';
            [$identifier, $action] = $this->resolveArtisanHandler($command);
        }

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

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function resolveCallbackEvent(CallbackEvent $event): array
    {
        try {
            $callback = (new ReflectionClass($event))->getProperty('callback')->getValue($event);
        } catch (Throwable) {
            return ['', '', 'callback'];
        }

        if ($callback instanceof Closure) {
            $jobHandler = $this->resolveScheduledJobFromClosure($callback);
            if ($jobHandler !== null) {
                return [$jobHandler[0], $jobHandler[1], 'job'];
            }

            return ['', '', 'callback'];
        }

        [$identifier, $action] = $this->resolveCallableHandler($callback);

        return [$identifier, $action, 'callback'];
    }

    /**
     * Laravel Schedule::job() wraps dispatch in a Closure that binds `$job`.
     *
     * @return null|array{0: string, 1: string}
     */
    private function resolveScheduledJobFromClosure(Closure $callback): ?array
    {
        try {
            $vars = (new ReflectionFunction($callback))->getStaticVariables();
        } catch (Throwable) {
            return null;
        }

        if (! array_key_exists('job', $vars)) {
            return null;
        }

        $job = $vars['job'];

        if (is_string($job) && $job !== '' && (class_exists($job) || interface_exists($job))) {
            $method = $this->resolveClassHandlerMethod($job);

            return [$job, $job.'@'.$method];
        }

        if (is_object($job)) {
            $class = $job::class;
            $method = $this->resolveClassHandlerMethod($class);

            return [$class, $class.'@'.$method];
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveCallableHandler(mixed $callback): array
    {
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

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveArtisanHandler(string $command): array
    {
        if ($command === '') {
            return ['', ''];
        }

        $commandClass = $this->resolveArtisanCommandClass($command);
        if ($commandClass === null) {
            return ['', ''];
        }

        $method = $this->resolveClassHandlerMethod($commandClass);

        return [$commandClass, $commandClass.'@'.$method];
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
