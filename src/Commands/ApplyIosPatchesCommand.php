<?php

declare(strict_types=1);

namespace Goodm4ven\NativePatches\Commands;

use Goodm4ven\NativePatches\Commands\Concerns\InteractsWithPatchFiles;
use Goodm4ven\NativePatches\Commands\Concerns\PatchesEdgeComponents;
use Goodm4ven\NativePatches\Commands\Concerns\PatchesIosContentView;
use Native\Mobile\Plugins\Commands\NativePluginHookCommand;
use RuntimeException;

class ApplyIosPatchesCommand extends NativePluginHookCommand
{
    use InteractsWithPatchFiles;
    use PatchesEdgeComponents;
    use PatchesIosContentView;

    protected $signature = 'nativephp:muttasiq:patches-ios';

    public function handle(): int
    {
        if (! $this->isIos()) {
            $this->info('Skipping Muttasiq iOS patches (non-iOS build).');

            return self::SUCCESS;
        }

        $buildPath = $this->buildPath();
        if ($buildPath === '' || ! is_dir($buildPath)) {
            $this->error('iOS build path is missing; cannot apply patches.');

            return self::FAILURE;
        }

        $hadErrors = false;

        try {
            $this->patchEdgeComponents(base_path());
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            $hadErrors = true;
        }

        $contentViewPath = $buildPath.'/NativePHP/ContentView.swift';

        try {
            $this->verifyIosSystemUi($contentViewPath);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            $hadErrors = true;
        }

        try {
            $this->patchIosBackHandler($contentViewPath);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            $hadErrors = true;
        }

        return $hadErrors ? self::FAILURE : self::SUCCESS;
    }
}
