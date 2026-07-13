<?php

namespace Neo4j\LaravelBoost\Support;

use Composer\InstalledVersions;

final class DevDependencyConfigPublisher
{
    private const PACKAGE_NAME = 'neo4j/laravel-boost';

    public function publishIfMissing(
        string $source,
        string $destination,
        string $basePath,
        ?bool $installedAsDevDependency = null,
    ): bool {
        if (file_exists($destination)) {
            return false;
        }

        if (! is_dir(dirname($destination))) {
            return false;
        }

        $isDevDependency = $installedAsDevDependency ?? $this->isInstalledAsDevDependency($basePath);

        if (! $isDevDependency) {
            return false;
        }

        if (! is_readable($source)) {
            return false;
        }

        return copy($source, $destination);
    }

    public function isInstalledAsDevDependencyFromComposerLock(string $basePath): bool
    {
        return $this->isListedInComposerLockDevPackages($basePath);
    }

    public function isInstalledAsDevDependency(string $basePath): bool
    {
        if (class_exists(InstalledVersions::class)
            && InstalledVersions::isInstalled(self::PACKAGE_NAME)) {
            foreach (InstalledVersions::getAllRawData() as $installed) {
                $package = $installed['versions'][self::PACKAGE_NAME] ?? null;

                if ($package !== null) {
                    return ($package['dev_requirement'] ?? false) === true;
                }
            }
        }

        return $this->isListedInComposerLockDevPackages($basePath);
    }

    private function isListedInComposerLockDevPackages(string $basePath): bool
    {
        $lockPath = $basePath.'/composer.lock';

        if (! is_readable($lockPath)) {
            return false;
        }

        $lock = json_decode((string) file_get_contents($lockPath), true);

        if (! is_array($lock)) {
            return false;
        }

        foreach ($lock['packages-dev'] ?? [] as $package) {
            if (($package['name'] ?? '') === self::PACKAGE_NAME) {
                return true;
            }
        }

        return false;
    }
}
