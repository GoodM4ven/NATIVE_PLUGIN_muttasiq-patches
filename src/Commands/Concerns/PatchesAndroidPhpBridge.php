<?php

declare(strict_types=1);

namespace Goodm4ven\NativePatches\Commands\Concerns;

use RuntimeException;

trait PatchesAndroidPhpBridge
{
    private function patchPhpBridge(string $path): void
    {
        if (! file_exists($path)) {
            $this->info("[native-persistent-runtime-guard] skip missing: {$path}");

            return;
        }

        $text = file_get_contents($path);
        if ($text === false) {
            throw new RuntimeException("[native-persistent-runtime-guard] error: unable to read {$path}");
        }

        $changed = false;

$bootPersistentRuntimeBody = <<<'KOTLIN'
val future = phpExecutor.submit<Boolean> {
    val start = System.currentTimeMillis()

    // Set up env vars needed for bootstrap
    ensureRuntimeInitialized()

    val result = nativePersistentBoot(persistentBootstrapScript)
    val elapsed = System.currentTimeMillis() - start

    if (result == 0) {
        val probeOutput = nativePersistentArtisan("about --version")
        val persistentRuntimeIsUsable =
            !probeOutput.contains("Runtime not booted", ignoreCase = true)
                && !probeOutput.contains("Persistent runtime not initialized", ignoreCase = true)
                && !probeOutput.contains("Artisan error:", ignoreCase = true)

        if (persistentRuntimeIsUsable) {
            persistentBooted = true
            persistentMode = true
            Log.i(TAG, "Persistent runtime booted in ${elapsed}ms")

            true
        } else {
            Log.e(
                TAG,
                "Persistent runtime probe failed after ${elapsed}ms: ${probeOutput.take(200)}"
            )
            nativePersistentShutdown()
            persistentBooted = false
            persistentMode = false

            false
        }
    } else {
        Log.e(TAG, "Persistent runtime boot FAILED (code=$result) after ${elapsed}ms")
        persistentBooted = false
        persistentMode = false
        false
    }
}
return future.get()
KOTLIN;

        [$text, $updated] = $this->setKotlinFunctionBody(
            $text,
            'bootPersistentRuntime',
            $bootPersistentRuntimeBody,
        );
        $changed = $changed || $updated;

$handleLaravelRequestBody = <<<'KOTLIN'
val requestStart = System.currentTimeMillis()

val future = phpExecutor.submit<String> {
    val prepStart = System.currentTimeMillis()

    // Clear Inertia-related env vars first - they persist between requests
    // and cause Laravel to return JSON instead of HTML
    val inertiaEnvVars = listOf(
        "HTTP_X_INERTIA",
        "HTTP_X_INERTIA_VERSION",
        "HTTP_X_INERTIA_PARTIAL_DATA",
        "HTTP_X_INERTIA_PARTIAL_COMPONENT",
        "HTTP_X_INERTIA_PARTIAL_EXCEPT"
    )
    inertiaEnvVars.forEach { envVar ->
        nativeSetEnv(envVar, "", 1)
    }

    request.headers.forEach { (key, value) ->
        val envKey = "HTTP_" + key.replace("-", "_").uppercase()
        nativeSetEnv(envKey, value, 1)
    }

    val cookieHeader = LaravelCookieStore.asCookieHeader()
    nativeSetEnv("HTTP_COOKIE", cookieHeader, 1)

    val prepTime = System.currentTimeMillis() - prepStart
    val jniStart = System.currentTimeMillis()

    val output = if (persistentMode && persistentBooted) {
        val persistentOutput = nativePersistentDispatch(
            request.method,
            request.uri,
            request.body,
            nativePhpScript
        )

        if (
            persistentOutput.contains(
                "Persistent dispatch error: Runtime not booted",
                ignoreCase = true
            )
        ) {
            Log.e(
                TAG,
                "Persistent dispatch lost boot state; shutting down persistent runtime and falling back to classic mode"
            )

            nativePersistentShutdown()
            persistentBooted = false
            persistentMode = false

            nativeHandleRequest(
                request.method,
                request.uri,
                request.body,
                nativePhpScript
            )
        } else {
            persistentOutput
        }
    } else {
        // Classic mode: full init/shutdown per request
        ensureRuntimeInitialized()
        nativeHandleRequest(
            request.method,
            request.uri,
            request.body,
            nativePhpScript
        )
    }

    val jniTime = System.currentTimeMillis() - jniStart
    val processStart = System.currentTimeMillis()

    val processedOutput = processRawPHPResponse(output)

    val processTime = System.currentTimeMillis() - processStart
    val mode = if (persistentMode && persistentBooted) "PERSISTENT" else "CLASSIC"
    Log.d(
        "PerfTiming",
        "BRIDGE[$mode] [${request.uri}] prep=${prepTime}ms jni=${jniTime}ms process=${processTime}ms"
    )

    processedOutput
}

val result = future.get()
val totalTime = System.currentTimeMillis() - requestStart
Log.d("PerfTiming", "BRIDGE_TOTAL [${request.uri}] ${totalTime}ms")
return result
KOTLIN;

        [$text, $updated] = $this->setKotlinFunctionBody(
            $text,
            'handleLaravelRequest',
            $handleLaravelRequestBody,
            );
        $changed = $changed || $updated;

        $this->writePatchResult($path, $text, $changed, 'native-persistent-runtime-guard');
    }
}
