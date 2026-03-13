<?php

declare(strict_types=1);

namespace Goodm4ven\NativePatches;

use Goodm4ven\NativePatches\Commands\ApplyAndroidPatchesCommand;
use Goodm4ven\NativePatches\Commands\ApplyIosPatchesCommand;
use Goodm4ven\NativePatches\Commands\RunNativePatchesCommand;
use Illuminate\Support\ServiceProvider;

class NativePatchesServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                RunNativePatchesCommand::class,
                ApplyAndroidPatchesCommand::class,
                ApplyIosPatchesCommand::class,
            ]);
        }
    }
}
