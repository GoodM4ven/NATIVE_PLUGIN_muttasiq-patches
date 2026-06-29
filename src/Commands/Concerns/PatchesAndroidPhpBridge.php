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

        // Muttasiq: make the PHP runtime thread + init flag process-wide.
        //
        // Upstream gives every PHPBridge instance its own newSingleThreadExecutor()
        // and its own `runtimeInitialized` flag. But the embedded PHP / TSRM state
        // primed by nativeRuntimeInit() is process-global and bound to whichever OS
        // thread first ran it. When a second PHPBridge instance is created (activity
        // recreate / resume), it boots PHP on a different pool thread and rebinds that
        // global state — and the first instance's still-live request thread then
        // SIGSEGVs in ts_resource_ex+276 (via OnUpdateBool during php_tsrm_startup_ex)
        // on its next php_embed_init. Funnel all PHP work through one shared thread and
        // prime exactly once per process so global PHP/TSRM state is only ever touched
        // from its owning thread.
        $changed = $this->replaceOnceOrError(
            $text,
            '    private val phpExecutor = java.util.concurrent.Executors.newSingleThreadExecutor()',
            '    private val phpExecutor get() = sharedPhpExecutor',
            'native-persistent-runtime-guard: shared phpExecutor',
            'private val phpExecutor get() = sharedPhpExecutor',
        ) || $changed;

        $changed = $this->replaceOnceOrError(
            $text,
            "    @Volatile\n    private var runtimeInitialized = false\n\n    @Volatile\n    private var persistentMode = false",
            "    @Volatile\n    private var persistentMode = false",
            'native-persistent-runtime-guard: drop instance runtimeInitialized',
            'private var sharedRuntimeInitialized = false',
        ) || $changed;

        // Anchor on the companion `init {}` (not MAX_REQUEST_AGE) and use
        // insertBeforeOrError so re-running the patch is a no-op once the shared
        // statics are present — a replaceOnce whose replacement re-includes its
        // own anchor would otherwise append a duplicate block on every run.
        $changed = $this->insertBeforeOrError(
            $text,
            "        init {\n            System.loadLibrary(\"php_wrapper\")",
            "        // Muttasiq: one process-wide PHP thread + one-time init so embedded\n".
            "        // PHP/TSRM global state is only ever touched from its owning thread,\n".
            "        // even across multiple PHPBridge instances (activity recreate/resume).\n".
            "        private val sharedPhpExecutor = java.util.concurrent.Executors.newSingleThreadExecutor()\n\n".
            "        @Volatile\n".
            "        private var sharedRuntimeInitialized = false\n",
            'native-persistent-runtime-guard: shared runtime statics',
        ) || $changed;

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

        $ensureRuntimeInitializedBody = <<<'KOTLIN'
synchronized(PHPBridge::class.java) {
    if (!sharedRuntimeInitialized) {
        val threadName = Thread.currentThread().name
        Log.i(TAG, "Initializing PHP runtime on thread=$threadName")
        nativeRuntimeInit()
        sharedRuntimeInitialized = true
        Log.i(TAG, "PHP runtime initialized on thread=$threadName")
    }
}
KOTLIN;

        [$text, $updated] = $this->setKotlinFunctionBody(
            $text,
            'ensureRuntimeInitialized',
            $ensureRuntimeInitializedBody,
        );
        $changed = $changed || $updated;

        $this->writePatchResult($path, $text, $changed, 'native-persistent-runtime-guard');
    }
}
