<?php

namespace Neo4j\LaravelBoost\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use ZipArchive;

class Neo4jMcpInstaller
{
    private const GITHUB_RELEASE_URL = 'https://github.com/neo4j/mcp/releases/download';

    private const DOWNLOAD_TIMEOUT_SECONDS = 120;

    /** @var array<string, string> Canonical platform key => GitHub release asset filename */
    private const PLATFORM_ASSETS = [
        'Linux_x86_64' => 'neo4j-mcp_Linux_x86_64.tar.gz',
        'Linux_arm64' => 'neo4j-mcp_Linux_arm64.tar.gz',
        'Linux_i386' => 'neo4j-mcp_Linux_i386.tar.gz',
        'Darwin_x86_64' => 'neo4j-mcp_Darwin_x86_64.tar.gz',
        'Darwin_arm64' => 'neo4j-mcp_Darwin_arm64.tar.gz',
        'Windows_x86_64' => 'neo4j-mcp_Windows_x86_64.zip',
        'Windows_arm64' => 'neo4j-mcp_Windows_arm64.zip',
        'Windows_i386' => 'neo4j-mcp_Windows_i386.zip',
    ];

    /** @var array<string, string> Common OS-arch slugs (e.g. linux-amd64) => canonical platform key */
    private const PLATFORM_ALIASES = [
        'darwin-arm64' => 'Darwin_arm64',
        'darwin-amd64' => 'Darwin_x86_64',
        'darwin-x86_64' => 'Darwin_x86_64',
        'linux-arm64' => 'Linux_arm64',
        'linux-amd64' => 'Linux_x86_64',
        'linux-x86_64' => 'Linux_x86_64',
        'linux-386' => 'Linux_i386',
        'linux-i386' => 'Linux_i386',
        'windows-arm64' => 'Windows_arm64',
        'windows-amd64' => 'Windows_x86_64',
        'windows-x86_64' => 'Windows_x86_64',
        'windows-386' => 'Windows_i386',
        'windows-i386' => 'Windows_i386',
    ];

    public function isInstalled(): bool
    {
        return is_file($this->getBinaryPath());
    }

    public function getBinaryPath(): string
    {
        $configuredPath = config('neo4j-boost.neo4j_mcp.binary_path');

        if (is_string($configuredPath) && $configuredPath !== '') {
            return $configuredPath;
        }

        $defaultPath = storage_path('app/neo4j-mcp/neo4j-mcp');

        if (PHP_OS_FAMILY === 'Windows') {
            return $defaultPath.'.exe';
        }

        return $defaultPath;
    }

    public function install(bool $force = false): void
    {
        if (! $force && $this->isInstalled()) {
            return;
        }

        $downloadUrl = $this->getDownloadUrl();
        if ($downloadUrl === null) {
            throw new RuntimeException(
                'Unsupported platform for Neo4j MCP binary download. Set neo4j-boost.neo4j_mcp.platform_asset (e.g. Linux_x86_64 or linux-amd64).'
            );
        }

        $response = Http::timeout(self::DOWNLOAD_TIMEOUT_SECONDS)->get($downloadUrl);
        if (! $response->successful()) {
            throw new RuntimeException(
                'Failed to download Neo4j MCP binary: HTTP '.$response->status().' from '.$downloadUrl
            );
        }

        $binaryPath = $this->getBinaryPath();
        $installDirectory = dirname($binaryPath);

        if (! is_dir($installDirectory) && ! @mkdir($installDirectory, 0755, true) && ! is_dir($installDirectory)) {
            throw new RuntimeException('Cannot create directory: '.$installDirectory);
        }

        $isZipArchive = str_ends_with($downloadUrl, '.zip');
        $temporaryArchivePath = $installDirectory.'/._neo4j_mcp_download.'.($isZipArchive ? 'zip' : 'tar.gz');

        file_put_contents($temporaryArchivePath, $response->body());

        try {
            if ($isZipArchive) {
                $this->extractZip($temporaryArchivePath, $installDirectory);
            } else {
                $this->extractTarGz($temporaryArchivePath, $installDirectory);
            }

            $extractedExecutable = $this->findExecutableInDirectory($installDirectory);
            if ($extractedExecutable === null) {
                throw new RuntimeException('Executable neo4j-mcp not found inside downloaded archive.');
            }

            if (realpath($extractedExecutable) !== realpath($binaryPath)) {
                if (is_file($binaryPath)) {
                    @unlink($binaryPath);
                }
                if (! @rename($extractedExecutable, $binaryPath)) {
                    throw new RuntimeException('Failed to move Neo4j MCP binary to '.$binaryPath);
                }
            }

            @chmod($binaryPath, 0755);
        } finally {
            @unlink($temporaryArchivePath);
            $this->removeExtractedArtifacts($installDirectory, $binaryPath);
        }
    }

    public function getDownloadUrl(): ?string
    {
        $assetFilename = $this->resolvePlatformAssetFilename();
        if ($assetFilename === null) {
            return null;
        }

        $version = (string) config('neo4j-boost.neo4j_mcp.version', 'v1.4.0');

        return self::GITHUB_RELEASE_URL.'/'.$version.'/'.$assetFilename;
    }

    protected function resolvePlatformAssetFilename(): ?string
    {
        $platformAsset = config('neo4j-boost.neo4j_mcp.platform_asset');

        if (is_string($platformAsset) && $platformAsset !== '') {
            if (str_starts_with($platformAsset, 'neo4j-mcp_')) {
                return $platformAsset;
            }

            $platformKey = $this->normalizePlatformKey($platformAsset);

            return self::PLATFORM_ASSETS[$platformKey] ?? null;
        }

        return $this->detectPlatformAssetFilename();
    }

    protected function detectPlatformAssetFilename(): ?string
    {
        $platformKey = $this->detectPlatformKey();

        if ($platformKey === null) {
            return null;
        }

        return self::PLATFORM_ASSETS[$platformKey] ?? null;
    }

    protected function detectPlatformKey(): ?string
    {
        $osFamily = match (PHP_OS_FAMILY) {
            'Darwin' => 'Darwin',
            'Linux' => 'Linux',
            'Windows' => 'Windows',
            default => null,
        };

        if ($osFamily === null) {
            return null;
        }

        return $osFamily.'_'.$this->normalizeArchitecture(php_uname('m'));
    }

    protected function normalizePlatformKey(string $platform): string
    {
        $normalized = strtolower(str_replace('_', '-', $platform));

        if (isset(self::PLATFORM_ALIASES[$normalized])) {
            return self::PLATFORM_ALIASES[$normalized];
        }

        if (isset(self::PLATFORM_ASSETS[$platform])) {
            return $platform;
        }

        $underscoredKey = str_replace('-', '_', $platform);
        if (preg_match('/^(darwin|linux|windows)_(.+)$/i', $underscoredKey, $matches)) {
            $os = match (strtolower($matches[1])) {
                'darwin' => 'Darwin',
                'linux' => 'Linux',
                'windows' => 'Windows',
                default => ucfirst(strtolower($matches[1])),
            };
            $arch = $this->normalizeArchitecture($matches[2]);

            return $os.'_'.$arch;
        }

        return $platform;
    }

    protected function normalizeArchitecture(string $machine): string
    {
        $normalized = strtolower($machine);

        return match ($normalized) {
            'aarch64', 'arm64' => 'arm64',
            'x86_64', 'amd64' => 'x86_64',
            'i386', 'i686', '386' => 'i386',
            default => $machine,
        };
    }

    protected function extractTarGz(string $archivePath, string $destinationDirectory): void
    {
        $phar = new \PharData($archivePath);
        $phar->extractTo($destinationDirectory);
    }

    protected function extractZip(string $archivePath, string $destinationDirectory): void
    {
        $zip = new ZipArchive;
        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('Failed to open ZIP archive: '.$archivePath);
        }

        $zip->extractTo($destinationDirectory);
        $zip->close();
    }

    protected function findExecutableInDirectory(string $directory): ?string
    {
        $executableNames = ['neo4j-mcp', 'neo4j-mcp.exe'];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            if (in_array($file->getFilename(), $executableNames, true)) {
                return $file->getPathname();
            }
        }

        return null;
    }

    protected function removeExtractedArtifacts(string $directory, string $binaryPath): void
    {
        $binaryRealPath = realpath($binaryPath);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $directory,
                \RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveDirectoryIterator::FOLLOW_SYMLINKS
            ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            $path = $file->getPathname();

            if ($binaryRealPath !== false && realpath($path) === $binaryRealPath) {
                continue;
            }

            if ($file->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
    }
}
