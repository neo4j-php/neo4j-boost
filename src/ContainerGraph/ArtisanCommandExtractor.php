<?php

namespace Neo4j\LaravelBoost\ContainerGraph;

use Closure;
use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Support\Facades\Artisan;
use ReflectionClass;
use ReflectionFunction;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Command\LazyCommand;

/**
 * Discovers every registered Artisan command from the live console application
 * and maps class-based commands to container Abstracts for HANDLED_BY edges.
 *
 * Closure commands (Artisan::command() in routes/console.php) are exported as
 * ArtisanCommand nodes without HANDLED_BY, same as closure scheduled tasks.
 */
final class ArtisanCommandExtractor
{
    /**
     * @param  null|array<string, mixed>  $commands  command name => command instance
     * @return array<int, array{
     *     key: string,
     *     name: string,
     *     description: string,
     *     hidden: bool,
     *     aliases: string,
     *     kind: string,
     *     source: string,
     *     action: string,
     *     identifier: string,
     *     identifier_kind: string
     * }>
     */
    public function extract(?array $commands = null): array
    {
        $commands ??= Artisan::all();

        $rows = [];
        $seen = [];

        foreach ($commands as $registeredName => $command) {
            if ($command instanceof LazyCommand) {
                $command = $command->getCommand();
            }

            if (! $command instanceof SymfonyCommand) {
                continue;
            }

            $name = (string) $command->getName();
            if ($name === '' || isset($seen[$name])) {
                continue;
            }

            // Symfony registers each alias as an extra key pointing at the same command.
            if (is_string($registeredName) && $registeredName !== $name) {
                continue;
            }

            $seen[$name] = true;
            $rows[] = $this->mapCommand($name, $command);
        }

        return $rows;
    }

    /**
     * @return array{key: string, name: string, description: string, hidden: bool, aliases: string, kind: string, source: string, action: string, identifier: string, identifier_kind: string}
     */
    private function mapCommand(string $name, SymfonyCommand $command): array
    {
        $row = [
            'key' => $name,
            'name' => $name,
            'description' => $command->getDescription(),
            'hidden' => $command->isHidden(),
            'aliases' => implode(',', $command->getAliases()),
        ];

        if ($command instanceof ClosureCommand) {
            return $row + [
                'kind' => 'closure',
                'source' => $this->sourceForFile($this->closureFile($command)),
                'action' => '',
                'identifier' => '',
                'identifier_kind' => '',
            ];
        }

        $reflection = new ReflectionClass($command);
        $class = $reflection->getName();

        return $row + [
            'kind' => 'class',
            'source' => $this->sourceForFile((string) $reflection->getFileName()),
            'action' => $class.'@'.$this->handlerMethod($reflection),
            'identifier' => $class,
            'identifier_kind' => 'Class',
        ];
    }

    /**
     * Mirrors Illuminate\Console\Command::execute(): handle() wins over __invoke();
     * plain Symfony commands run execute().
     */
    private function handlerMethod(ReflectionClass $reflection): string
    {
        foreach (['handle', '__invoke'] as $method) {
            if ($reflection->hasMethod($method) && $reflection->getMethod($method)->isPublic()) {
                return $method;
            }
        }

        return 'execute';
    }

    private function closureFile(ClosureCommand $command): string
    {
        // ClosureCommand::$callback is protected with no public getter; reflection
        // tracks Laravel internals and may need updates if the framework changes.
        $callback = (new ReflectionClass($command))->getProperty('callback')->getValue($command);

        return $callback instanceof Closure
            ? (string) (new ReflectionFunction($callback))->getFileName()
            : '';
    }

    private function sourceForFile(string $file): string
    {
        $path = str_replace('\\', '/', $file);

        if (str_contains($path, '/vendor/laravel/framework/') || str_contains($path, '/vendor/symfony/console/')) {
            return 'framework';
        }

        return str_contains($path, '/vendor/') ? 'vendor' : 'app';
    }
}
