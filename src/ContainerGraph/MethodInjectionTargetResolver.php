<?php

namespace Neo4j\LaravelBoost\ContainerGraph;

use Illuminate\Console\Command as ArtisanCommand;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Routing\Controller;
use ReflectionClass;
use ReflectionMethod;

/**
 * Maps Laravel entry-point classes to method names inspected for container method injection.
 */
final class MethodInjectionTargetResolver
{
    /** @var list<string> */
    private const CONTROLLER_SKIP_METHODS = [
        '__construct',
        '__destruct',
        '__call',
        '__callStatic',
    ];

    /**
     * @return list<string>
     */
    public function methodsForClass(ReflectionClass $class): array
    {
        if ($this->isConsoleCommand($class)) {
            return $this->hasPublicMethod($class, 'handle') ? ['handle'] : [];
        }

        if ($this->isController($class)) {
            return $this->controllerActionMethods($class);
        }

        if ($this->isMiddleware($class)) {
            return $this->hasPublicMethod($class, 'handle') ? ['handle'] : [];
        }

        if ($this->isJob($class)) {
            return $this->hasPublicMethod($class, 'handle') ? ['handle'] : [];
        }

        if ($this->isListener($class)) {
            return $this->hasPublicMethod($class, 'handle') ? ['handle'] : [];
        }

        return [];
    }

    private function isConsoleCommand(ReflectionClass $class): bool
    {
        return $class->isSubclassOf(ArtisanCommand::class);
    }

    private function isController(ReflectionClass $class): bool
    {
        if ($class->isAbstract() || $class->getShortName() === 'Controller') {
            return false;
        }

        if ($class->isSubclassOf(Controller::class)) {
            return true;
        }

        // Laravel 11+ skeleton: App\Http\Controllers\Controller does not extend
        // Illuminate\Routing\Controller. Match concrete classes under Http\Controllers.
        if (str_contains($class->getName(), '\\Http\\Controllers\\') && $class->getParentClass() !== false) {
            return true;
        }

        return $this->extendsSkeletonControllerBase($class);
    }

    private function extendsSkeletonControllerBase(ReflectionClass $class): bool
    {
        $parent = $class->getParentClass();
        while ($parent !== false) {
            if ($parent->getShortName() === 'Controller'
                && str_contains($parent->getName(), '\\Controllers\\')) {
                return true;
            }

            $parent = $parent->getParentClass();
        }

        return false;
    }

    public function isMiddleware(ReflectionClass $class): bool
    {
        if (interface_exists('Illuminate\Contracts\Http\Middleware\Middleware')
            && $class->implementsInterface('Illuminate\Contracts\Http\Middleware\Middleware')) {
            return true;
        }

        return str_contains($class->getName(), '\\Middleware\\')
            && $this->hasPublicMethod($class, 'handle');
    }

    private function isJob(ReflectionClass $class): bool
    {
        if ($this->isConsoleCommand($class)) {
            return false;
        }

        if ($class->implementsInterface(ShouldQueue::class)) {
            return true;
        }

        if (str_ends_with($class->getShortName(), 'Job')) {
            return true;
        }

        return str_contains($class->getName(), '\\Jobs\\');
    }

    public function isListener(ReflectionClass $class): bool
    {
        if ($this->isConsoleCommand($class) || $this->isController($class) || $this->isJob($class)) {
            return false;
        }

        if (str_ends_with($class->getShortName(), 'Listener')) {
            return true;
        }

        return str_contains($class->getName(), '\\Listeners\\');
    }

    /**
     * @return list<string>
     */
    private function controllerActionMethods(ReflectionClass $class): array
    {
        $methods = [];

        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic()) {
                continue;
            }

            if ($method->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }

            $name = $method->getName();
            if (in_array($name, self::CONTROLLER_SKIP_METHODS, true)) {
                continue;
            }

            $methods[] = $name;
        }

        sort($methods);

        return $methods;
    }

    private function hasPublicMethod(ReflectionClass $class, string $method): bool
    {
        if (! $class->hasMethod($method)) {
            return false;
        }

        return $class->getMethod($method)->isPublic();
    }
}
