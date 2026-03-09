<?php

declare(strict_types=1);

namespace Goodm4ven\NativePatches;

use Goodm4ven\NativePatches\Commands\ApplyNativePatchesCommand;
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
                ApplyNativePatchesCommand::class,
            ]);
        }
    }
}
