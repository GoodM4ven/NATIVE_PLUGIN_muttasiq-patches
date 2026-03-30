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
        setenv("LARAVEL_STORAGE_PATH", storageDir, 1)
        setenv("VIEW_COMPILED_PATH", viewCacheDir, 1)
        setenv("DB_DATABASE", "\(databaseDir)/database.sqlite", 1)

        // Set APP_KEY from secure storage (generates on first run)
SWIFT,
            <<<'SWIFT'
        setenv("LARAVEL_STORAGE_PATH", storageDir, 1)
        setenv("VIEW_COMPILED_PATH", viewCacheDir, 1)
        setenv("DB_DATABASE", "\(databaseDir)/database.sqlite", 1)
        setenv("DB_CONNECTION", "sqlite", 1)
        setenv("NATIVEPHP_RUNNING", "true", 1)

        // Set APP_KEY from secure storage (generates on first run)
SWIFT,
            'iOS sqlite runtime environment',
            'setenv("DB_CONNECTION", "sqlite", 1)',
        ) || $changed;

        $changed = $this->replaceOnceOrError(
            $text,
            <<<'SWIFT'
        setenv("PHP_SELF", "artisan.php", 1)
        setenv("APP_RUNNING_IN_CONSOLE", "true", 1)

        let additionalCArgs = additionalArgs.map { strdup($0) }
SWIFT,
            <<<'SWIFT'
        setenv("PHP_SELF", "artisan.php", 1)
        setenv("APP_RUNNING_IN_CONSOLE", "true", 1)
        setenv("NATIVEPHP_RUNNING", "true", 1)
        setenv("DB_CONNECTION", "sqlite", 1)

        let additionalCArgs = additionalArgs.map { strdup($0) }
SWIFT,
            'iOS artisan sqlite runtime env',
            'setenv("DB_CONNECTION", "sqlite", 1)',
        ) || $changed;

        $changed = $this->replaceOnceOrError(
            $text,
            <<<'SWIFT'
            NSLog("[NativePHP] Classic mode configured — skipping persistent runtime boot")
            booted = false
            createStorageLink()
        } else {
SWIFT,
            <<<'SWIFT'
            NSLog("[NativePHP] Classic mode configured — skipping persistent runtime boot")
            booted = false
            createStorageLink()
            NSLog("[NativePHP] artisan migrate START (classic mode)")
            migrateDatabase()
            NSLog("[NativePHP] artisan migrate DONE")
        } else {
SWIFT,
            'iOS classic mode migration bootstrap',
            'artisan migrate START (classic mode)',
        ) || $changed;

        $changed = $this->replaceOnceOrError(
            $text,
            <<<'SWIFT'
                NSLog("[NativePHP] persistent boot failed, falling back to classic mode")
                createStorageLink()
            }
SWIFT,
            <<<'SWIFT'
                NSLog("[NativePHP] persistent boot failed, falling back to classic mode")
                createStorageLink()
                NSLog("[NativePHP] artisan migrate START (persistent fallback)")
                migrateDatabase()
                NSLog("[NativePHP] artisan migrate DONE")
            }
SWIFT,
            'iOS persistent fallback migration bootstrap',
            'artisan migrate START (persistent fallback)',
        ) || $changed;

        $this->writePatchResult($path, $text, $changed, 'native-ios-db-bootstrap');
    }
}
