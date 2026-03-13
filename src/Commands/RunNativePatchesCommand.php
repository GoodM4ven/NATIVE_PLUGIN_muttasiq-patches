<?php

declare(strict_types=1);

namespace Goodm4ven\NativePatches\Commands;

use Native\Mobile\Plugins\Commands\NativePluginHookCommand;

class RunNativePatchesCommand extends NativePluginHookCommand
{
    protected $signature = 'nativephp:muttasiq:patches';

    public function handle(): int
    {
        if ($this->isAndroid()) {
            return $this->call('nativephp:muttasiq:patches-android', $this->hookOptions());
        }

        if ($this->isIos()) {
            return $this->call('nativephp:muttasiq:patches-ios', $this->hookOptions());
        }

        $this->info('Skipping Muttasiq native patches (unsupported platform build).');

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function hookOptions(): array
    {
        return array_filter([
            '--platform' => (string) $this->option('platform'),
            '--build-path' => (string) $this->option('build-path'),
            '--plugin-path' => (string) $this->option('plugin-path'),
            '--app-id' => (string) $this->option('app-id'),
            '--config' => (string) $this->option('config'),
            '--plugins' => (string) $this->option('plugins'),
        ], fn (string $value): bool => $value !== '');
    }
}
