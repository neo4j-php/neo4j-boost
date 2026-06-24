<?php

namespace Neo4j\LaravelBoost\StaticAnalysis;

use Neo4j\LaravelBoost\ResolutionCatalog\GlobalHelperCatalog;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Finds Laravel global helper calls in PHP files (SOFT-47).
 */
final class GlobalHelperEdgeFinder
{
    public function __construct(
        private GlobalHelperCatalog $catalog,
    ) {}

    /**
     * @param  array<int, string>  $paths
     * @return list<GlobalHelperEdge>
     */
    public function scanPaths(array $paths): array
    {
        $edges = [];

        foreach ($paths as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            if (is_file($path) && str_ends_with($path, '.php')) {
                $edges = array_merge($edges, $this->scanFile($path));

                continue;
            }

            if (! is_dir($path)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $edges = array_merge($edges, $this->scanFile($file->getPathname()));
            }
        }

        return $this->uniqueEdges($edges);
    }

    /**
     * @return list<GlobalHelperEdge>
     */
    public function scanSource(string $source, string $file = 'inline.php'): array
    {
        $parser = (new ParserFactory)->createForVersion(PhpVersion::fromString('8.2'));
        $ast = $parser->parse($source);

        if ($ast === null) {
            return [];
        }

        $visitor = new GlobalHelperFileVisitor($file, $this->catalog);
        $traverser = new NodeTraverser;
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->edges();
    }

    /**
     * @return list<GlobalHelperEdge>
     */
    private function scanFile(string $file): array
    {
        $contents = @file_get_contents($file);
        if ($contents === false) {
            return [];
        }

        return $this->scanSource($contents, $file);
    }

    /**
     * @param  list<GlobalHelperEdge>  $edges
     * @return list<GlobalHelperEdge>
     */
    private function uniqueEdges(array $edges): array
    {
        $seen = [];
        $unique = [];

        foreach ($edges as $edge) {
            $key = json_encode([
                $edge->class,
                $edge->dependency,
                $edge->helper,
                $edge->file,
                $edge->line,
            ]);

            if ($key === false || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $edge;
        }

        return $unique;
    }
}
