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
dbFile.parentFile?.mkdirs()

if (!dbFile.exists() || dbFile.length() == 0L) {
    Log.d(TAG, "📄 Creating empty SQLite file: ${dbFile.absolutePath}")
    dbFile.createNewFile()
} else {
    Log.d(TAG, "✅ SQLite file already exists: ${dbFile.absolutePath}")
}

val storagePublicDir = File(appStorageDir, "persisted_data/storage/app/public")
if (!storagePublicDir.exists()) {
    phpBridge.runArtisanCommand("storage:unlink")
    phpBridge.runArtisanCommand("storage:link")
}
phpBridge.runArtisanCommand("app:native-bootstrap --no-interaction")
KOTLIN;

        [$text, $updated] = $this->setKotlinFunctionBody(
            $text,
            'runBaseArtisanCommands',
            $runBaseArtisanCommandsBody,
        );
        $changed = $changed || $updated;

        $endpointOverrideBlock = $this->androidNativeEndpointOverridesBlock();

        $textWithoutLegacyOverrides = $this->stripLegacyAndroidNativeRuntimeOverrides($text);
        $changed = $changed || $textWithoutLegacyOverrides !== $text;
        $text = $textWithoutLegacyOverrides;

        if ($endpointOverrideBlock !== null) {
            $changed = $this->replaceOnceOrError(
                $text,
                <<<'KOTLIN'
"NATIVEPHP_TEMPDIR" to context.cacheDir.absolutePath
            )
KOTLIN,
                <<<'KOTLIN'
"NATIVEPHP_TEMPDIR" to context.cacheDir.absolutePath
            )

KOTLIN.$endpointOverrideBlock,
                'native-runtime-endpoint-overrides',
                'NATIVE_QURAN_SNAPSHOT_DOWNLOAD_ENDPOINT',
            ) || $changed;
        }

        $changed = $this->replaceOnceOrError(
            $text,
            '            Log.d(TAG, "✅ Environment variables configured")',
            <<<'KOTLIN'
            logMuttasiqNativeEnvironmentSummary()
            Log.d(TAG, "✅ Environment variables configured")
KOTLIN,
            'native-runtime-environment-summary-call',
            'logMuttasiqNativeEnvironmentSummary()',
        ) || $changed;

        $environmentSummaryBody = <<<'KOTLIN'
val keys = listOf(
    "QUEUE_CONNECTION",
    "NATIVE_ANDROID_KEEP_LOOPBACK_ENDPOINTS",
    "NATIVE_QURAN_LOCAL_LAN_IP",
    "NATIVE_ATHKAR_ENDPOINT",
    "NATIVE_SETTINGS_ENDPOINT",
    "NATIVE_QURAN_SNAPSHOT_META_ENDPOINT",
    "NATIVE_QURAN_SNAPSHOT_DOWNLOAD_ENDPOINT",
    "NATIVE_JS_ERROR_REPORTS_ENDPOINT"
)

for (key in keys) {
    val value = System.getenv(key)
    val renderedValue = if (value.isNullOrBlank()) "<unset>" else value
    Log.i(TAG, "🌐 Muttasiq native env [$key]=$renderedValue")
}
KOTLIN;

        $changed = $this->insertBeforeOrError(
            $text,
            <<<'KOTLIN'
    private fun setEnvironmentVariables(vararg pairs: Pair<String, String>) {
KOTLIN,
            $this->buildKotlinFunctionDefinition('logMuttasiqNativeEnvironmentSummary', $environmentSummaryBody),
            'native-runtime-environment-summary-definition',
        ) || $changed;

        $this->writePatchResult($path, $text, $changed, 'native-bundle-extract');
    }

    /**
     * @return array<string, string>
     */
    private function androidNativeEndpointOverrides(): array
    {
        $overrides = [
            'QUEUE_CONNECTION' => 'sync',
            'NATIVE_ANDROID_KEEP_LOOPBACK_ENDPOINTS' => $this->shouldKeepAndroidLoopbackEndpointOverrides() ? '1' : '0',
        ];

        $resolvedLanIpv4 = $this->resolveLocalLanIpv4();

        if ($resolvedLanIpv4 !== null) {
            $overrides['NATIVE_QURAN_LOCAL_LAN_IP'] = $resolvedLanIpv4;
        }

        $endpointEnvironmentKeys = [
            'NATIVE_ATHKAR_ENDPOINT',
            'NATIVE_SETTINGS_ENDPOINT',
            'NATIVE_QURAN_SNAPSHOT_META_ENDPOINT',
            'NATIVE_QURAN_SNAPSHOT_DOWNLOAD_ENDPOINT',
            'NATIVE_JS_ERROR_REPORTS_ENDPOINT',
        ];

        foreach ($endpointEnvironmentKeys as $key) {
            $value = trim((string) getenv($key));

            if ($value === '' || preg_match('/^https?:\/\//i', $value) !== 1) {
                continue;
            }

            $overrides[$key] = $this->normalizeAndroidEndpointOverride($value);
        }

        return $overrides;
    }

    private function normalizeAndroidEndpointOverride(string $url): string
    {
        if ($this->shouldKeepAndroidLoopbackEndpointOverrides()) {
            return $url;
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            return $url;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($host, ['localhost', '127.0.0.1'], true)) {
            return $url;
        }

        $lanIpv4 = $this->resolveLocalLanIpv4();

        if ($lanIpv4 === null) {
            return $url;
        }

        return $this->rebuildHttpUrlWithHost($parts, $lanIpv4);
    }

    private function shouldKeepAndroidLoopbackEndpointOverrides(): bool
    {
        $value = strtolower(trim((string) getenv('NATIVE_ANDROID_KEEP_LOOPBACK_ENDPOINTS')));

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private function resolveLocalLanIpv4(): ?string
    {
        $explicitLanIpv4 = trim((string) getenv('NATIVE_QURAN_LOCAL_LAN_IP'));

        if ($this->isLocalLanIpv4($explicitLanIpv4)) {
            return $explicitLanIpv4;
        }

        $ipRouteOutput = trim((string) @shell_exec('ip route get 1.1.1.1 2>/dev/null'));

        if ($ipRouteOutput !== '' && preg_match('/\bsrc\s+((?:\d{1,3}\.){3}\d{1,3})\b/', $ipRouteOutput, $matches) === 1) {
            $candidate = trim((string) ($matches[1] ?? ''));

            if ($this->isLocalLanIpv4($candidate)) {
                return $candidate;
            }
        }

        $hostIpsOutput = trim((string) @shell_exec('hostname -I 2>/dev/null'));

        if ($hostIpsOutput === '') {
            return null;
        }

        $candidates = preg_split('/\s+/', $hostIpsOutput) ?: [];

        foreach ($candidates as $candidate) {
            $normalizedCandidate = trim((string) $candidate);

            if ($this->isLocalLanIpv4($normalizedCandidate)) {
                return $normalizedCandidate;
            }
        }

        return null;
    }

    private function isLocalLanIpv4(string $candidate): bool
    {
        if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        if (
            str_starts_with($candidate, '10.')
            || str_starts_with($candidate, '192.168.')
            || str_starts_with($candidate, '169.254.')
        ) {
            return true;
        }

        if (preg_match('/^172\.(\d{1,2})\./', $candidate, $matches) !== 1) {
            return false;
        }

        $secondOctet = (int) $matches[1];

        return $secondOctet >= 16 && $secondOctet <= 31;
    }

    /**
     * @param  array<string, int|string>  $parts
     */
    private function rebuildHttpUrlWithHost(array $parts, string $host): string
    {
        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        $user = (string) ($parts['user'] ?? '');
        $password = (string) ($parts['pass'] ?? '');
        $auth = '';

        if ($user !== '') {
            $auth = $user;

            if ($password !== '') {
                $auth .= ':'.$password;
            }

            $auth .= '@';
        }

        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '/');
        $query = isset($parts['query']) ? '?'.(string) $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#'.(string) $parts['fragment'] : '';

        return "{$scheme}://{$auth}{$host}{$port}{$path}{$query}{$fragment}";
    }

    private function androidNativeEndpointOverridesBlock(): ?string
    {
        $endpointOverrides = $this->androidNativeEndpointOverrides();

        if ($endpointOverrides === []) {
            return null;
        }

        $lines = ['            setEnvironmentVariables('];
        $totalOverrides = count($endpointOverrides);
        $index = 0;

        foreach ($endpointOverrides as $key => $value) {
            $index++;
            $suffix = $index < $totalOverrides ? ',' : '';
            $lines[] = '                "'.$key.'" to '.$this->kotlinStringLiteral($value).$suffix;
        }

        $lines[] = '            )';

        return implode("\n", $lines);
    }

    private function stripLegacyAndroidNativeRuntimeOverrides(string $text): string
    {
        $updatedText = preg_replace_callback(
            '/^[ \t]*setEnvironmentVariables\(\n(?:[ \t]*"[^"]+" to [^\n]+\n)+[ \t]*\)\n/m',
            static function (array $matches): string {
                $block = (string) ($matches[0] ?? '');

                if (
                    ! str_contains($block, '"NATIVE_')
                    && ! str_contains($block, '"QUEUE_CONNECTION" to "sync"')
                ) {
                    return $block;
                }

                return '';
            },
            $text,
        );

        if (! is_string($updatedText)) {
            throw new RuntimeException('Unable to strip legacy Android native runtime override blocks.');
        }

        return $updatedText;
    }

    private function kotlinStringLiteral(string $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded)) {
            throw new RuntimeException('Unable to encode Kotlin string literal for Android endpoint override patch.');
        }

        return $encoded;
    }
}
