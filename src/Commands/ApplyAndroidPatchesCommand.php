<?php

declare(strict_types=1);

namespace Goodm4ven\NativePatches\Commands;

use Goodm4ven\NativePatches\Commands\Concerns\InteractsWithPatchFiles;
use Goodm4ven\NativePatches\Commands\Concerns\PatchesAndroidLaravelEnvironment;
use Goodm4ven\NativePatches\Commands\Concerns\PatchesAndroidMainActivity;
use Goodm4ven\NativePatches\Commands\Concerns\PatchesAndroidPhpBridge;
use Goodm4ven\NativePatches\Commands\Concerns\PatchesAndroidPhpWebViewClient;
use Goodm4ven\NativePatches\Commands\Concerns\PatchesAndroidWebViewManager;
use Goodm4ven\NativePatches\Commands\Concerns\PatchesEdgeComponents;
use Native\Mobile\Plugins\Commands\NativePluginHookCommand;
use RuntimeException;
use ZipArchive;

class ApplyAndroidPatchesCommand extends NativePluginHookCommand
{
    use InteractsWithPatchFiles;
    use PatchesAndroidLaravelEnvironment;
    use PatchesAndroidMainActivity;
    use PatchesAndroidPhpBridge;
    use PatchesAndroidPhpWebViewClient;
    use PatchesAndroidWebViewManager;
    use PatchesEdgeComponents;

    protected $signature = 'nativephp:muttasiq:patches-android';

    /**
     * @var list<string>
     */
    private const BUNDLED_LARAVEL_ARCHIVE_PRUNE_PREFIXES = [
        'vendor/fakerphp/',
        'vendor/goodm4ven/arabicable/resources/raw-data/quran/exegesis/',
        'vendor/larastan/',
        'vendor/mockery/',
        'vendor/pestphp/',
        'vendor/phpstan/',
        'vendor/phpunit/',
    ];

    /**
     * @var array<string, bool>|null
     */
    private ?array $bundledLaravelManifestVendors = null;

    /**
     * @var array<string, bool>|null
     */
    private ?array $bundledLaravelAutoloadFileVendors = null;

    public function handle(): int
    {
        if (! $this->isAndroid()) {
            $this->info('Skipping Muttasiq Android patches (non-Android build).');

            return self::SUCCESS;
        }

        $buildPath = $this->buildPath();
        if ($buildPath === '' || ! is_dir($buildPath)) {
            $this->error('Android build path is missing; cannot apply patches.');

            return self::FAILURE;
        }

        $hadErrors = false;

        try {
            $this->patchEdgeComponents(base_path());
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            $hadErrors = true;
        }

        $mainActivityPath = $buildPath.'/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt';
        try {
            $this->patchMainActivity($mainActivityPath);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            $hadErrors = true;
        }

        $webViewManagerPath = $buildPath.'/app/src/main/java/com/nativephp/mobile/network/WebViewManager.kt';
        try {
            $this->patchWebViewManager($webViewManagerPath);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            $hadErrors = true;
        }

        $phpWebViewClientPath = $buildPath.'/app/src/main/java/com/nativephp/mobile/network/PHPWebViewClient.kt';
        try {
            $this->patchPhpWebViewClient($phpWebViewClientPath);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            $hadErrors = true;
        }

        $phpBridgePath = $buildPath.'/app/src/main/java/com/nativephp/mobile/bridge/PHPBridge.kt';
        try {
            $this->patchPhpBridge($phpBridgePath);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            $hadErrors = true;
        }

        $laravelEnvironmentPath = $buildPath.'/app/src/main/java/com/nativephp/mobile/bridge/LaravelEnvironment.kt';
        try {
            $this->patchLaravelEnvironment($laravelEnvironmentPath);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            $hadErrors = true;
        }

        $laravelBundleArchivePath = $buildPath.'/app/src/main/assets/laravel_bundle.zip';
        try {
            $this->pruneBundledLaravelArchive($laravelBundleArchivePath);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            $hadErrors = true;
        }

        return $hadErrors ? self::FAILURE : self::SUCCESS;
    }

    private function pruneBundledLaravelArchive(string $archivePath): void
    {
        if (! is_file($archivePath)) {
            throw new RuntimeException("Laravel bundle archive is missing at [{$archivePath}].");
        }

        $originalArchive = new ZipArchive;
        if ($originalArchive->open($archivePath) !== true) {
            throw new RuntimeException("Unable to open Laravel bundle archive at [{$archivePath}].");
        }

        $temporaryArchivePath = $archivePath.'.tmp';
        @unlink($temporaryArchivePath);

        $prunedArchive = new ZipArchive;
        if ($prunedArchive->open($temporaryArchivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $originalArchive->close();

            throw new RuntimeException("Unable to create temporary Laravel bundle archive at [{$temporaryArchivePath}].");
        }

        $temporaryFiles = [];
        $removedEntriesCount = 0;
        $removedEntriesSize = 0;

        try {
            for ($index = 0; $index < $originalArchive->numFiles; $index++) {
                $stat = $originalArchive->statIndex($index);
                $entryName = (string) ($stat['name'] ?? '');

                if ($entryName === '') {
                    continue;
                }

                if ($this->shouldPruneBundledLaravelArchiveEntry($entryName)) {
                    $removedEntriesCount++;
                    $removedEntriesSize += (int) ($stat['size'] ?? 0);

                    continue;
                }

                if (str_ends_with($entryName, '/')) {
                    $prunedArchive->addEmptyDir(rtrim($entryName, '/'));
                    $this->copyArchiveEntryMetadata($originalArchive, $prunedArchive, $index, $entryName);

                    continue;
                }

                $temporaryFilePath = $this->copyArchiveEntryToTemporaryFile($originalArchive, $entryName);
                $temporaryFiles[] = $temporaryFilePath;

                if (! $prunedArchive->addFile($temporaryFilePath, $entryName)) {
                    throw new RuntimeException("Unable to copy [{$entryName}] into the pruned Laravel bundle archive.");
                }

                $this->copyArchiveEntryMetadata($originalArchive, $prunedArchive, $index, $entryName);
            }
        } catch (\Throwable $throwable) {
            $prunedArchive->close();
            $originalArchive->close();
            $this->deleteTemporaryFiles($temporaryFiles);
            @unlink($temporaryArchivePath);

            throw new RuntimeException($throwable->getMessage(), previous: $throwable);
        }

        $prunedArchive->close();
        $originalArchive->close();
        $this->deleteTemporaryFiles($temporaryFiles);

        if (! rename($temporaryArchivePath, $archivePath)) {
            @unlink($temporaryArchivePath);

            throw new RuntimeException("Unable to replace Laravel bundle archive at [{$archivePath}] after pruning.");
        }

        $removedEntriesSizeInMegabytes = number_format($removedEntriesSize / 1024 / 1024, 2);
        $this->info("Pruned {$removedEntriesCount} dormant Laravel bundle entries ({$removedEntriesSizeInMegabytes} MB) from [{$archivePath}].");
    }

    private function shouldPruneBundledLaravelArchiveEntry(string $entryName): bool
    {
        foreach (self::BUNDLED_LARAVEL_ARCHIVE_PRUNE_PREFIXES as $prefix) {
            if (str_starts_with($entryName, $prefix)) {
                if ($this->shouldKeepBundledLaravelArchiveVendorPrefix($prefix)) {
                    return false;
                }

                return true;
            }
        }

        return false;
    }

    private function shouldKeepBundledLaravelArchiveVendorPrefix(string $prefix): bool
    {
        if (! preg_match('#^vendor/([^/]+)/$#', $prefix, $matches)) {
            return false;
        }

        $vendorName = (string) ($matches[1] ?? '');
        if ($vendorName === '') {
            return false;
        }

        return ($this->bundledLaravelManifestVendors()[$vendorName] ?? false)
            || ($this->bundledLaravelAutoloadFileVendors()[$vendorName] ?? false);
    }

    /**
     * @return array<string, bool>
     */
    private function bundledLaravelManifestVendors(): array
    {
        if (is_array($this->bundledLaravelManifestVendors)) {
            return $this->bundledLaravelManifestVendors;
        }

        $manifestPath = base_path('bootstrap/cache/packages.php');
        if (! is_file($manifestPath)) {
            return $this->bundledLaravelManifestVendors = [];
        }

        /** @var array<string, mixed> $packages */
        $packages = require $manifestPath;

        $vendors = [];

        foreach (array_keys($packages) as $packageName) {
            if (! is_string($packageName) || ! str_contains($packageName, '/')) {
                continue;
            }

            [$vendorName] = explode('/', $packageName, 2);
            $vendors[$vendorName] = true;
        }

        return $this->bundledLaravelManifestVendors = $vendors;
    }

    /**
     * @return array<string, bool>
     */
    private function bundledLaravelAutoloadFileVendors(): array
    {
        if (is_array($this->bundledLaravelAutoloadFileVendors)) {
            return $this->bundledLaravelAutoloadFileVendors;
        }

        $autoloadFilesPath = base_path('vendor/composer/autoload_files.php');
        if (! is_file($autoloadFilesPath)) {
            return $this->bundledLaravelAutoloadFileVendors = [];
        }

        /** @var array<string, string> $autoloadFiles */
        $autoloadFiles = require $autoloadFilesPath;

        $vendors = [];

        foreach ($autoloadFiles as $autoloadFile) {
            if (! is_string($autoloadFile)) {
                continue;
            }

            if (! preg_match('#/vendor/([^/]+)/[^/]+/#', $autoloadFile, $matches)) {
                continue;
            }

            $vendorName = (string) ($matches[1] ?? '');
            if ($vendorName === '') {
                continue;
            }

            $vendors[$vendorName] = true;
        }

        return $this->bundledLaravelAutoloadFileVendors = $vendors;
    }

    private function copyArchiveEntryToTemporaryFile(ZipArchive $archive, string $entryName): string
    {
        $inputStream = $archive->getStream($entryName);
        if ($inputStream === false) {
            throw new RuntimeException("Unable to stream [{$entryName}] from the original Laravel bundle archive.");
        }

        $temporaryFilePath = tempnam(sys_get_temp_dir(), 'muttasiq-native-archive-');
        if ($temporaryFilePath === false) {
            fclose($inputStream);

            throw new RuntimeException('Unable to allocate a temporary file while pruning the Laravel bundle archive.');
        }

        $temporaryFileHandle = fopen($temporaryFilePath, 'wb');
        if ($temporaryFileHandle === false) {
            fclose($inputStream);
            @unlink($temporaryFilePath);

            throw new RuntimeException("Unable to open temporary file [{$temporaryFilePath}] while pruning the Laravel bundle archive.");
        }

        stream_copy_to_stream($inputStream, $temporaryFileHandle);

        fclose($temporaryFileHandle);
        fclose($inputStream);

        return $temporaryFilePath;
    }

    private function copyArchiveEntryMetadata(ZipArchive $sourceArchive, ZipArchive $targetArchive, int $sourceIndex, string $entryName): void
    {
        $sourceStat = $sourceArchive->statIndex($sourceIndex);
        $compressionMethod = $sourceStat['comp_method'] ?? false;

        if (is_int($compressionMethod)) {
            $targetArchive->setCompressionName($entryName, $compressionMethod);
        }

        $entryComment = $sourceArchive->getCommentIndex($sourceIndex);
        if ($entryComment !== false && $entryComment !== '') {
            $targetArchive->setCommentName($entryName, $entryComment);
        }

        $operatingSystem = null;
        $externalAttributes = null;

        if ($sourceArchive->getExternalAttributesIndex($sourceIndex, $operatingSystem, $externalAttributes, ZipArchive::FL_UNCHANGED)) {
            $targetArchive->setExternalAttributesName(
                $entryName,
                (int) $operatingSystem,
                (int) $externalAttributes,
                ZipArchive::FL_UNCHANGED,
            );
        }
    }

    /**
     * @param  list<string>  $temporaryFiles
     */
    private function deleteTemporaryFiles(array $temporaryFiles): void
    {
        foreach ($temporaryFiles as $temporaryFile) {
            @unlink($temporaryFile);
        }
    }
}
