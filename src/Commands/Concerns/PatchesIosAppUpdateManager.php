<?php

declare(strict_types=1);

namespace Goodm4ven\NativePatches\Commands\Concerns;

use RuntimeException;

trait PatchesIosAppUpdateManager
{
    private function patchIosAppUpdateManager(string $path): void
    {
        if (! file_exists($path)) {
            $this->info("[native-ios-app-updates] skip missing: {$path}");

            return;
        }

        $text = file_get_contents($path);
        if ($text === false) {
            throw new RuntimeException("[native-ios-app-updates] error: unable to read {$path}");
        }

        $changed = false;

        $runMigrationsAndClearCachesBody = <<<'SWIFT'
print("🔄 Running migrations and clearing caches...")

guard let app = NativePHPApp.shared else {
    print("❌ NativePHPApp.shared not available")
    return
}

_ = app.artisan(additionalArgs: ["app:native-bootstrap", "--no-interaction"])
_ = app.artisan(additionalArgs: ["view:clear"])

print("✅ Migrations and cache clearing completed")
SWIFT;

        [$text, $updated] = $this->setSwiftFunctionBody(
            $text,
            'runMigrationsAndClearCaches',
            $runMigrationsAndClearCachesBody,
        );
        $changed = $changed || $updated;

        $this->writePatchResult($path, $text, $changed, 'native-ios-app-updates');
    }
}
