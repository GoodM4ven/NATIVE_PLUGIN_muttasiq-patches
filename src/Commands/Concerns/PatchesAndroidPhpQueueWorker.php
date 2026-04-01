<?php

declare(strict_types=1);

namespace Goodm4ven\NativePatches\Commands\Concerns;

use RuntimeException;

trait PatchesAndroidPhpQueueWorker
{
    private function patchPhpQueueWorker(string $path): void
    {
        if (! file_exists($path)) {
            $this->info("[native-queue-worker-verbosity] skip missing: {$path}");

            return;
        }

        $text = file_get_contents($path);
        if ($text === false) {
            throw new RuntimeException("[native-queue-worker-verbosity] error: unable to read {$path}");
        }

        $changed = false;

        $changed = $this->replaceOnceOrError(
            $text,
            '                    val output = phpBridge.runWorkerArtisan("queue:work --once --quiet")',
            '                    val output = phpBridge.runWorkerArtisan("queue:work --once -v --no-interaction")',
            'queue worker artisan verbosity',
            'queue:work --once -v --no-interaction',
        ) || $changed;

        $changed = $this->replaceOnceOrError(
            $text,
            <<<'KOTLIN'
                    if (output.isNotEmpty() && output != "0") {
                        Log.d(TAG, "Job output: ${output.take(200)}")
                    }
KOTLIN,
            <<<'KOTLIN'
                    if (output.isNotEmpty() && output != "0") {
                        Log.i(TAG, "Queue worker output: ${output.take(500)}")
                    }
KOTLIN,
            'queue worker output logging',
            'Queue worker output: ${output.take(500)}',
        ) || $changed;

        $changed = $this->replaceOnceOrError(
            $text,
            <<<'KOTLIN'
                    val sleepMs = if (output.contains("Processed", ignoreCase = true)) {
                        SLEEP_INTERVAL_MS
                    } else {
                        SLEEP_IDLE_MS
                    }
KOTLIN,
            <<<'KOTLIN'
                    val handledQueueWork =
                        output.contains("Processing", ignoreCase = true)
                            || output.contains("Processed", ignoreCase = true)
                            || output.contains("Failed", ignoreCase = true)
                            || output.contains("Exception", ignoreCase = true)

                    val sleepMs = if (handledQueueWork) {
                        SLEEP_INTERVAL_MS
                    } else {
                        SLEEP_IDLE_MS
                    }
KOTLIN,
            'queue worker sleep heuristics',
            'val handledQueueWork =',
        ) || $changed;

        $this->writePatchResult($path, $text, $changed, 'native-queue-worker-verbosity');
    }
}
