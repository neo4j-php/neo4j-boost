<?php

namespace Neo4j\LaravelBoost\ContainerGraph;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Neo4j\LaravelBoost\Support\Graph\TypeNameKind;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use ReflectionClass;
use RuntimeException;

/**
 * Exports every registered service provider (loaded and still-deferred) and the
 * container keys it registers, as hubs: ServiceProvider -[:REGISTERS]-> Abstract.
 *
 * Laravel does not record which provider created a binding, so REGISTERS edges
 * come from what each provider declares:
 *  - `bindings` / `singletons`: the provider's $bindings / $singletons properties;
 *  - `register`: $this->app->bind()/singleton()/scoped()/instance() (and *If variants)
 *    with a literal key in register() or the $this->method() helpers it calls, kept
 *    only when that key is bound;
 *  - `provides`: keys a deferred provider lists in provides().
 * Bindings made with variable keys, loops or helpers outside register() are not attributed.
 */
final class ServiceProviderExtractor
{
    private const REGISTER_METHODS = [
        'bind' => 'normal',
        'bindIf' => 'normal',
        'singleton' => 'singleton',
        'singletonIf' => 'singleton',
        'scoped' => 'scoped',
        'scopedIf' => 'scoped',
        'instance' => 'instance',
    ];

    private ?Parser $parser = null;

    /** @var array<string, array<Node>> */
    private array $parsedFiles = [];

    /**
     * @param  null|array<string, bool>  $loadedProviders  provider class => true, as Application::getLoadedProviders()
     * @param  null|array<string, string>  $deferredServices  service => provider class, as Application::getDeferredServices()
     * @param  null|callable(string): bool  $isBound
     * @return array{
     *     providers: list<array{key: string, name: string, deferred: bool, loaded: bool, source: string}>,
     *     registers: list<array{provider_key: string, abstract: string, abstract_kind: string, type: string, via: string}>
     * }
     */
    public function extract(?array $loadedProviders = null, ?array $deferredServices = null, ?callable $isBound = null): array
    {
        $loadedProviders ??= app()->getLoadedProviders();
        $deferredServices ??= app()->getDeferredServices();
        $isBound ??= static fn (string $abstract): bool => app()->bound($abstract);

        $classes = array_unique([...array_keys(array_filter($loadedProviders)), ...array_values($deferredServices)]);

        $providers = [];
        $registers = [];

        foreach ($classes as $class) {
            if (! class_exists($class) || ! is_subclass_of($class, ServiceProvider::class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            $deferred = $reflection->implementsInterface(DeferrableProvider::class);

            $providers[] = [
                'key' => $class,
                'name' => $reflection->getShortName(),
                'deferred' => $deferred,
                'loaded' => isset($loadedProviders[$class]),
                'source' => CodeSource::forFile((string) $reflection->getFileName()),
            ];

            $seen = [];
            $add = static function (string $abstract, string $type, string $via) use ($class, &$seen, &$registers): void {
                if ($abstract === '' || isset($seen[$abstract])) {
                    return;
                }

                $seen[$abstract] = true;
                $registers[] = [
                    'provider_key' => $class,
                    'abstract' => $abstract,
                    'abstract_kind' => TypeNameKind::for($abstract),
                    'type' => $type,
                    'via' => $via,
                ];
            };

            $defaults = $reflection->getDefaultProperties();
            foreach (['bindings' => 'normal', 'singletons' => 'singleton'] as $property => $type) {
                foreach ((array) ($defaults[$property] ?? []) as $key => $value) {
                    // Same key handling as Application::register().
                    $abstract = is_int($key) ? $value : $key;
                    if (is_string($abstract)) {
                        $add($abstract, $type, $property);
                    }
                }
            }

            foreach ($this->registerCalls($reflection) as [$abstract, $type]) {
                if ($isBound($abstract)) {
                    $add($abstract, $type, 'register');
                }
            }

            if ($deferred) {
                foreach ($this->providedServices($class, $deferredServices) as $abstract) {
                    $add($abstract, '', 'provides');
                }
            }
        }

        return ['providers' => $providers, 'registers' => $registers];
    }

    /**
     * Services still waiting in the deferred map plus provides() of the loaded instance
     * (Laravel drops a service from the map once it has been resolved).
     *
     * @param  array<string, string>  $deferredServices
     * @return list<string>
     */
    private function providedServices(string $class, array $deferredServices): array
    {
        $services = array_keys($deferredServices, $class, true);

        $instance = app()->getProvider($class);
        if ($instance instanceof ServiceProvider) {
            $services = [...$services, ...$instance->provides()];
        }

        return array_values(array_filter($services, 'is_string'));
    }

    /**
     * Container registrations in register(), following $this->method() calls into
     * helpers such as registerAccessGate() (framework providers split register() up).
     *
     * @param  ReflectionClass<ServiceProvider>  $reflection
     * @param  array<string, true>  $visited
     * @return list<array{0: string, 1: string}> container key => binding type
     */
    private function registerCalls(ReflectionClass $reflection, string $methodName = 'register', array &$visited = []): array
    {
        if (isset($visited[strtolower($methodName)]) || ! $reflection->hasMethod($methodName)) {
            return [];
        }
        $visited[strtolower($methodName)] = true;

        $method = $reflection->getMethod($methodName);
        $file = $method->getFileName();

        if ($file === false || $method->getDeclaringClass()->getName() === ServiceProvider::class) {
            return [];
        }

        // A parsed method starts at its first attribute; reflection starts at `function`.
        $line = $method->getStartLine();
        $finder = new NodeFinder;
        $body = $finder->findFirst(
            $this->parse($file),
            static fn (Node $node): bool => $node instanceof ClassMethod
                && $node->name->toLowerString() === strtolower($methodName)
                && $node->getStartLine() <= $line
                && $node->getEndLine() >= $line,
        );

        if (! $body instanceof ClassMethod) {
            return [];
        }

        $calls = [];
        foreach ($finder->findInstanceOf($body->stmts ?? [], MethodCall::class) as $call) {
            if (! $call->name instanceof Identifier) {
                continue;
            }

            if ($call->var instanceof Variable && $call->var->name === 'this') {
                $calls = [...$calls, ...$this->registerCalls($reflection, $call->name->toString(), $visited)];

                continue;
            }

            if (! $this->isApplication($call->var)) {
                continue;
            }

            $type = self::REGISTER_METHODS[$call->name->toString()] ?? null;
            $abstract = $this->literalKey($call->getArgs()[0]->value ?? null);

            if ($type !== null && $abstract !== null) {
                $calls[] = [$abstract, $type];
            }
        }

        return $calls;
    }

    /**
     * `$this->app` or `app()`.
     */
    private function isApplication(Node $node): bool
    {
        if ($node instanceof PropertyFetch) {
            return $node->var instanceof Variable
                && $node->var->name === 'this'
                && $node->name instanceof Identifier
                && $node->name->toString() === 'app';
        }

        return $node instanceof FuncCall
            && $node->name instanceof Name
            && $node->name->getLast() === 'app'
            && $node->getArgs() === [];
    }

    /**
     * `Foo::class` or a string literal; anything dynamic returns null.
     */
    private function literalKey(?Node $node): ?string
    {
        if ($node instanceof String_) {
            return $node->value;
        }

        if ($node instanceof ClassConstFetch
            && $node->class instanceof Name
            && ! $node->class->isSpecialClassName()
            && $node->name instanceof Identifier
            && $node->name->toLowerString() === 'class') {
            return $node->class->toString();
        }

        return null;
    }

    /**
     * @return array<Node>
     */
    private function parse(string $file): array
    {
        if (isset($this->parsedFiles[$file])) {
            return $this->parsedFiles[$file];
        }

        $source = file_get_contents($file);
        if ($source === false) {
            throw new RuntimeException("Cannot read service provider file {$file}");
        }

        $this->parser ??= (new ParserFactory)->createForVersion(PhpVersion::fromString('8.2'));
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);

        return $this->parsedFiles[$file] = $traverser->traverse($this->parser->parse($source) ?? []);
    }
}
