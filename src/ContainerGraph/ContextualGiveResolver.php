<?php

namespace Neo4j\LaravelBoost\ContainerGraph;

use Closure;
use Neo4j\LaravelBoost\Support\Graph\TypeNameKind;
use ReflectionFunction;
use ReflectionNamedType;
use Throwable;

/**
 * Best-effort resolution of contextual give() targets for container graph export.
 *
 * @see README.md "Contextual bindings" for documented limitations.
 */
final class ContextualGiveResolver
{
    /**
     * @return array<int, array{name: string, kind: string, reason: string}>
     */
    public function resolve(mixed $implementation, string $needs): array
    {
        if (is_string($implementation)) {
            $name = trim($implementation);
            if ($name === '') {
                return [];
            }

            return [[
                'name' => $name,
                'kind' => TypeNameKind::for($name),
                'reason' => '',
            ]];
        }

        if (is_array($implementation)) {
            $rows = [];

            foreach ($implementation as $item) {
                foreach ($this->resolve($item, $needs) as $row) {
                    $rows[] = $row;
                }
            }

            return $rows;
        }

        if ($implementation instanceof Closure) {
            $resolved = $this->resolveClosure($implementation, $needs);
            if ($resolved !== null) {
                return [$resolved];
            }

            return [[
                'name' => 'closure@'.$needs,
                'kind' => 'Closure',
                'reason' => 'dynamic_give_closure',
            ]];
        }

        if (is_object($implementation)) {
            $name = $implementation::class;

            return [[
                'name' => $name,
                'kind' => TypeNameKind::for($name),
                'reason' => '',
            ]];
        }

        return [];
    }

    /**
     * @return null|array{name: string, kind: string, reason: string}
     */
    private function resolveClosure(Closure $closure, string $needs): ?array
    {
        try {
            $reflection = new ReflectionFunction($closure);
            $static = $reflection->getStaticVariables();

            if (isset($static['concrete']) && is_string($static['concrete'])) {
                $name = trim($static['concrete']);

                return $name === '' ? null : [
                    'name' => $name,
                    'kind' => TypeNameKind::for($name),
                    'reason' => '',
                ];
            }

            if (isset($static['abstract']) && is_string($static['abstract'])) {
                $name = trim($static['abstract']);

                return $name === '' ? null : [
                    'name' => $name,
                    'kind' => TypeNameKind::for($name),
                    'reason' => '',
                ];
            }

            $disk = $this->storageDiskFromClosureSource($reflection);
            if ($disk !== null) {
                return [
                    'name' => 'storage.disk:'.$disk,
                    'kind' => 'Alias',
                    'reason' => '',
                ];
            }

            $returnType = $reflection->getReturnType();
            if ($returnType instanceof ReflectionNamedType && ! $returnType->isBuiltin()) {
                $name = $returnType->getName();

                return [
                    'name' => $name,
                    'kind' => TypeNameKind::for($name),
                    'reason' => 'closure_return_type_only',
                ];
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function storageDiskFromClosureSource(ReflectionFunction $reflection): ?string
    {
        $file = $reflection->getFileName();
        if ($file === false || ! is_readable($file)) {
            return null;
        }

        $lines = file($file);
        if ($lines === false) {
            return null;
        }

        $snippet = implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));

        if (preg_match('/Storage::disk\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $snippet, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
