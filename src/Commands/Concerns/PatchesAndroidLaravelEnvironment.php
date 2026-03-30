<?php

declare(strict_types=1);

namespace Goodm4ven\NativePatches\Commands\Concerns;

use RuntimeException;

trait PatchesAndroidLaravelEnvironment
{
    private function patchLaravelEnvironment(string $path): void
    {
        if (! file_exists($path)) {
            $this->info("[native-bundle-extract] skip missing: {$path}");

            return;
        }

        $text = file_get_contents($path);
        if ($text === false) {
            throw new RuntimeException("[native-bundle-extract] error: unable to read {$path}");
        }

        $changed = false;

        $changed = $this->removeLine(
            $text,
            "import kotlinx.coroutines.*\n",
        );

$unzipBody = <<<'KOTLIN'
val buffer = ByteArray(65536)
ZipInputStream(BufferedInputStream(inputStream)).use { zis ->
    var ze: ZipEntry? = zis.nextEntry

    while (ze != null) {
        // Skip storage directory - we use persisted_data/storage instead
        if (ze.name.startsWith("storage/") || ze.name == "storage") {
            Log.d(TAG, "⏭️ Skipping storage directory from bundle: ${ze.name}")
            zis.closeEntry()
            ze = zis.nextEntry
            continue
        }

        val skipsDormantQuranExegesis =
            ze.name.startsWith("resources/raw-data/quran/exegesis/")
                || ze.name.contains("/resources/raw-data/quran/exegesis/")
                || ze.name.startsWith("vendor/goodm4ven/arabicable/resources/raw-data/quran/exegesis/")

        if (skipsDormantQuranExegesis) {
            Log.d(TAG, "⏭️ Skipping dormant Quran exegesis bundle entry: ${ze.name}")
            zis.closeEntry()
            ze = zis.nextEntry
            continue
        }

        val file = File(destinationDir, ze.name)
        val destinationRoot = destinationDir.canonicalPath + File.separator
        val destinationFile = file.canonicalPath

        // Prevent zip-slip path traversal
        if (!destinationFile.startsWith(destinationRoot)) {
            throw SecurityException("Blocked ZIP entry outside extraction dir: ${ze.name}")
        }

        if (ze.isDirectory) {
            file.mkdirs()
        } else {
            file.parentFile?.mkdirs()
            FileOutputStream(file).use { fos ->
                var count: Int
                while (zis.read(buffer).also { count = it } != -1) {
                    fos.write(buffer, 0, count)
                }
                fos.flush()
            }
        }

        zis.closeEntry()
        ze = zis.nextEntry
    }
}
KOTLIN;

        [$text, $updated] = $this->setKotlinFunctionBody($text, 'unzip', $unzipBody);
        $changed = $changed || $updated;

$runBaseArtisanCommandsBody = <<<'KOTLIN'
val dbFile = File(appStorageDir, "persisted_data/database/database.sqlite")
val bundledQuranSnapshotFile = File(appStorageDir, "database/native-quran-reader.sqlite")
dbFile.parentFile?.mkdirs()

if (!dbFile.exists() || dbFile.length() == 0L) {
    if (bundledQuranSnapshotFile.exists() && bundledQuranSnapshotFile.length() > 0L) {
        Log.d(TAG, "📚 Seeding SQLite file from bundled native Quran snapshot: ${bundledQuranSnapshotFile.absolutePath}")
        bundledQuranSnapshotFile.copyTo(dbFile, overwrite = true)
    } else {
        Log.d(TAG, "📄 Creating empty SQLite file: ${dbFile.absolutePath}")
        dbFile.createNewFile()
    }
} else {
    Log.d(TAG, "✅ SQLite file already exists: ${dbFile.absolutePath}")
}

File(appStorageDir, "persisted_data/storage/app/public")
phpBridge.runArtisanCommand("optimize:clear")
phpBridge.runArtisanCommand("storage:unlink")
phpBridge.runArtisanCommand("storage:link")
phpBridge.runArtisanCommand("app:native-bootstrap --no-interaction")
KOTLIN;

        [$text, $updated] = $this->setKotlinFunctionBody(
            $text,
            'runBaseArtisanCommands',
            $runBaseArtisanCommandsBody,
        );
        $changed = $changed || $updated;

        $this->writePatchResult($path, $text, $changed, 'native-bundle-extract');
    }
}
