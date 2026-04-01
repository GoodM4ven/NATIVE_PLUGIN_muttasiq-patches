<?php

declare(strict_types=1);

namespace Goodm4ven\NativePatches\Commands;

use Goodm4ven\NativePatches\Commands\Concerns\InteractsWithPatchFiles;
use Goodm4ven\NativePatches\Commands\Concerns\PatchesAndroidLaravelEnvironment;
use Goodm4ven\NativePatches\Commands\Concerns\PatchesAndroidMainActivity;
use Goodm4ven\NativePatches\Commands\Concerns\PatchesAndroidPhpBridge;
use Goodm4ven\NativePatches\Commands\Concerns\PatchesAndroidPhpQueueWorker;
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
    use PatchesAndroidPhpQueueWorker;
    use PatchesAndroidPhpWebViewClient;
    use PatchesAndroidWebViewManager;
    use PatchesEdgeComponents;

    protected $signature = 'nativephp:muttasiq:patches-android';

    /**
     * @var list<string>
     */
    private const BUNDLED_LARAVEL_ARCHIVE_PRUNE_PREFIXES = [
        'vendor/goodm4ven/arabicable/resources/raw-data/quran/exegesis/',
        'database/native-quran-reader.sqlite',
        'database/native-quran-reader.sqlite.gz',
        'database/native-quran-reader.json',
    ];

    private const BUNDLED_BUILD_ASSET_PREFIX = 'public/build/assets/';

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

        $phpQueueWorkerPath = $buildPath.'/app/src/main/java/com/nativephp/mobile/bridge/PHPQueueWorker.kt';
        try {
            $this->patchPhpQueueWorker($phpQueueWorkerPath);
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

        $retainedBuildAssetEntries = $this->retainedBundledBuildAssetEntries();

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
        $removedDormantQuranEntriesCount = 0;
        $removedStaleBuildEntriesCount = 0;

        try {
            for ($index = 0; $index < $originalArchive->numFiles; $index++) {
                $stat = $originalArchive->statIndex($index);
                $entryName = (string) ($stat['name'] ?? '');

                if ($entryName === '') {
                    continue;
                }

                $pruneReason = $this->bundledLaravelArchivePruneReason($entryName, $retainedBuildAssetEntries);

                if ($pruneReason !== null) {
                    $removedEntriesCount++;
                    $removedEntriesSize += (int) ($stat['size'] ?? 0);

                    if ($pruneReason === 'dormant-quran-exegesis') {
                        $removedDormantQuranEntriesCount++;
                    }

                    if ($pruneReason === 'stale-build-asset') {
                        $removedStaleBuildEntriesCount++;
                    }

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
        $this->info(
            "Pruned {$removedEntriesCount} Laravel bundle entries ({$removedEntriesSizeInMegabytes} MB) from [{$archivePath}]"
            ." [dormant-quran-exegesis={$removedDormantQuranEntriesCount}, stale-build-assets={$removedStaleBuildEntriesCount}].",
        );
    }

    /**
     * @param  array<string, true>|null  $retainedBuildAssetEntries
     */
    private function bundledLaravelArchivePruneReason(string $entryName, ?array $retainedBuildAssetEntries): ?string
    {
        foreach (self::BUNDLED_LARAVEL_ARCHIVE_PRUNE_PREFIXES as $prefix) {
            if (str_starts_with($entryName, $prefix)) {
                return 'dormant-quran-exegesis';
            }
        }

        if (
            $retainedBuildAssetEntries !== null
            && str_starts_with($entryName, self::BUNDLED_BUILD_ASSET_PREFIX)
            && ! isset($retainedBuildAssetEntries[$entryName])
        ) {
            return 'stale-build-asset';
        }

        return null;
    }

    /**
     * @return array<string, true>|null
     */
    private function retainedBundledBuildAssetEntries(): ?array
    {
        $manifestPath = base_path('public/build/manifest.json');

        if (! is_file($manifestPath)) {
            return null;
        }

        try {
            /** @var mixed $decodedManifest */
            $decodedManifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($decodedManifest)) {
            return null;
        }

        $retainedEntries = [];

        foreach ($decodedManifest as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $this->retainBundledBuildAssetEntry($retainedEntries, $entry['file'] ?? null);

            foreach ($entry['css'] ?? [] as $assetPath) {
                $this->retainBundledBuildAssetEntry($retainedEntries, $assetPath);
            }

            foreach ($entry['assets'] ?? [] as $assetPath) {
                $this->retainBundledBuildAssetEntry($retainedEntries, $assetPath);
            }
        }

        return $retainedEntries;
    }

    /**
     * @param  array<string, true>  $retainedEntries
     */
    private function retainBundledBuildAssetEntry(array &$retainedEntries, mixed $assetPath): void
    {
        if (! is_string($assetPath)) {
            return;
        }

        $normalizedAssetPath = ltrim($assetPath, '/');

        if ($normalizedAssetPath === '' || ! str_starts_with($normalizedAssetPath, 'assets/')) {
            return;
        }

        $retainedEntries['public/build/'.$normalizedAssetPath] = true;
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
