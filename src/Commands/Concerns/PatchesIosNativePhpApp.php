<?php

declare(strict_types=1);

namespace Goodm4ven\NativePatches\Commands\Concerns;

use RuntimeException;

trait PatchesIosNativePhpApp
{
    private function patchIosNativePhpApp(string $path): void
    {
        if (! file_exists($path)) {
            $this->info("[native-ios-db-bootstrap] skip missing: {$path}");

            return;
        }

        $text = file_get_contents($path);
        if ($text === false) {
            throw new RuntimeException("[native-ios-db-bootstrap] error: unable to read {$path}");
        }

        $changed = false;

        $changed = $this->replaceOnceOrError(
            $text,
            <<<'SWIFT'
        setenv("PHP_SELF", "artisan.php", 1)
        setenv("APP_RUNNING_IN_CONSOLE", "true", 1)
SWIFT,
            <<<'SWIFT'
        setenv("PHP_SELF", "artisan.php", 1)
        setenv("APP_RUNNING_IN_CONSOLE", "true", 1)
        setenv("NATIVEPHP_RUNNING", "true", 1)
        setenv("DB_CONNECTION", "sqlite", 1)
SWIFT,
            'iOS artisan sqlite runtime env',
            'setenv("DB_CONNECTION", "sqlite", 1)',
        ) || $changed;

        $changed = $this->replaceOnceOrError(
            $text,
            <<<'SWIFT'
        // 3. Create storage symlink
        DebugLogger.shared.log("📱 Deferred init: creating storage symlink")
        createStorageLink()

        // 4. Execute plugin initialization callbacks (on main thread)
SWIFT,
            <<<'SWIFT'
        // 3. Create storage symlink
        DebugLogger.shared.log("📱 Deferred init: creating storage symlink")
        createStorageLink()

        // 4. Ensure sqlite schema is up to date for native runtime
        DebugLogger.shared.log("📱 Deferred init: running migrations")
        migrateDatabase()

        // 5. Execute plugin initialization callbacks (on main thread)
SWIFT,
            'iOS deferred migration bootstrap',
            'DebugLogger.shared.log("📱 Deferred init: running migrations")',
        ) || $changed;

        $this->writePatchResult($path, $text, $changed, 'native-ios-db-bootstrap');
    }
}
