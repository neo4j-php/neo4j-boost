<?php

namespace Neo4j\LaravelBoost\Tests\Unit;

use Neo4j\LaravelBoost\Support\DevDependencyConfigPublisher;
use PHPUnit\Framework\TestCase;

class DevDependencyConfigPublisherTest extends TestCase
{
    private DevDependencyConfigPublisher $publisher;

    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publisher = new DevDependencyConfigPublisher;
        $this->basePath = sys_get_temp_dir().'/neo4j-boost-config-publish-'.uniqid('', true);
        mkdir($this->basePath.'/config', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_publishes_config_when_installed_as_dev_dependency_and_destination_missing(): void
    {
        $source = $this->basePath.'/package-config.php';
        $destination = $this->basePath.'/config/neo4j-boost.php';

        file_put_contents($source, "<?php\n\nreturn ['transport' => ['driver' => 'stdio']];\n");
        $this->writeComposerLock(packagesDev: [
            ['name' => 'neo4j/laravel-boost', 'version' => '1.0.0'],
        ]);

        $published = $this->publisher->publishIfMissing(
            $source,
            $destination,
            $this->basePath,
            installedAsDevDependency: true,
        );

        $this->assertTrue($published);
        $this->assertFileExists($destination);
        $this->assertSame(file_get_contents($source), file_get_contents($destination));
    }

    public function test_does_not_publish_when_config_already_exists(): void
    {
        $source = $this->basePath.'/package-config.php';
        $destination = $this->basePath.'/config/neo4j-boost.php';

        file_put_contents($source, "<?php\n\nreturn ['transport' => ['driver' => 'stdio']];\n");
        file_put_contents($destination, "<?php\n\nreturn ['custom' => true];\n");
        $this->writeComposerLock(packagesDev: [
            ['name' => 'neo4j/laravel-boost', 'version' => '1.0.0'],
        ]);

        $published = $this->publisher->publishIfMissing(
            $source,
            $destination,
            $this->basePath,
            installedAsDevDependency: true,
        );

        $this->assertFalse($published);
        $this->assertSame("<?php\n\nreturn ['custom' => true];\n", file_get_contents($destination));
    }

    public function test_does_not_publish_when_installed_as_production_dependency(): void
    {
        $source = $this->basePath.'/package-config.php';
        $destination = $this->basePath.'/config/neo4j-boost.php';

        file_put_contents($source, "<?php\n\nreturn ['transport' => ['driver' => 'stdio']];\n");
        $this->writeComposerLock(packages: [
            ['name' => 'neo4j/laravel-boost', 'version' => '1.0.0'],
        ]);

        $published = $this->publisher->publishIfMissing(
            $source,
            $destination,
            $this->basePath,
            installedAsDevDependency: false,
        );

        $this->assertFalse($published);
        $this->assertFileDoesNotExist($destination);
    }

    public function test_does_not_publish_when_not_installed_as_dev_dependency(): void
    {
        $source = $this->basePath.'/package-config.php';
        $destination = $this->basePath.'/config/neo4j-boost.php';

        file_put_contents($source, "<?php\n\nreturn ['transport' => ['driver' => 'stdio']];\n");

        $published = $this->publisher->publishIfMissing($source, $destination, $this->basePath);

        $this->assertFalse($published);
        $this->assertFileDoesNotExist($destination);
    }

    public function test_detects_dev_dependency_from_composer_lock(): void
    {
        $this->writeComposerLock(packagesDev: [
            ['name' => 'neo4j/laravel-boost', 'version' => '1.0.0'],
        ]);

        $this->assertTrue($this->publisher->isInstalledAsDevDependencyFromComposerLock($this->basePath));
    }

    public function test_does_not_detect_production_dependency_from_composer_lock(): void
    {
        $this->writeComposerLock(packages: [
            ['name' => 'neo4j/laravel-boost', 'version' => '1.0.0'],
        ]);

        $this->assertFalse($this->publisher->isInstalledAsDevDependencyFromComposerLock($this->basePath));
    }

    public function test_does_not_detect_dev_dependency_when_composer_lock_is_missing(): void
    {
        $this->assertFalse($this->publisher->isInstalledAsDevDependencyFromComposerLock($this->basePath));
    }

    /**
     * @param  list<array{name: string, version: string}>  $packages
     * @param  list<array{name: string, version: string}>  $packagesDev
     */
    private function writeComposerLock(array $packages = [], array $packagesDev = []): void
    {
        file_put_contents($this->basePath.'/composer.lock', json_encode([
            'packages' => $packages,
            'packages-dev' => $packagesDev,
        ], JSON_THROW_ON_ERROR));
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.'/'.$item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
