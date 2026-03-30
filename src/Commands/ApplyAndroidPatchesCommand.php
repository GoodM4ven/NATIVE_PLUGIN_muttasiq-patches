<?php

declare(strict_types=1);

namespace Goodm4ven\NativePatches\Commands;

use Goodm4ven\NativePatches\Commands\Concerns\InteractsWithPatchFiles;
use Goodm4ven\NativePatches\Commands\Concerns\PatchesAndroidLaravelEnvironment;
use Goodm4ven\NativePatches\Commands\Concerns\PatchesAndroidMainActivity;
use Goodm4ven\NativePatches\Commands\Concerns\PatchesAndroidPhpWebViewClient;
use Goodm4ven\NativePatches\Commands\Concerns\PatchesAndroidWebViewManager;
use Goodm4ven\NativePatches\Commands\Concerns\PatchesEdgeComponents;
use Native\Mobile\Plugins\Commands\NativePluginHookCommand;
use RuntimeException;

class ApplyAndroidPatchesCommand extends NativePluginHookCommand
{
    use InteractsWithPatchFiles;
    use PatchesAndroidLaravelEnvironment;
    use PatchesAndroidMainActivity;
    use PatchesAndroidPhpWebViewClient;
    use PatchesAndroidWebViewManager;
    use PatchesEdgeComponents;

    protected $signature = 'nativephp:muttasiq:patches-android';

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

        $laravelEnvironmentPath = $buildPath.'/app/src/main/java/com/nativephp/mobile/bridge/LaravelEnvironment.kt';
        try {
            $this->patchLaravelEnvironment($laravelEnvironmentPath);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            $hadErrors = true;
        }

        return $hadErrors ? self::FAILURE : self::SUCCESS;
    }
}
