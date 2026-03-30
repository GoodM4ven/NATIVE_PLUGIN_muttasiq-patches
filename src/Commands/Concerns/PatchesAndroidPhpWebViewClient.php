<?php

declare(strict_types=1);

namespace Goodm4ven\NativePatches\Commands\Concerns;

use RuntimeException;

trait PatchesAndroidPhpWebViewClient
{
    private function patchPhpWebViewClient(string $path): void
    {
        if (! file_exists($path)) {
            $this->info("[native-android-assets] skip missing: {$path}");

            return;
        }

        $text = file_get_contents($path);
        if ($text === false) {
            throw new RuntimeException("[native-android-assets] error: unable to read {$path}");
        }

        $changed = false;

        $handleAssetRequestBody = <<<'KOTLIN'
val path = when {
    url.contains("/_assets/") -> {
        url.substring(url.indexOf("_assets/") + 8)
    }
    url.startsWith("http://127.0.0.1/") || url.startsWith("https://127.0.0.1/") -> {
        val startIndex = url.indexOf("127.0.0.1/") + 10
        url.substring(startIndex)
    }
    else -> {
        url.substring(url.lastIndexOf("/") + 1)
    }
}

val cleanPath = path.split("?")[0]
Log.d(TAG, "🗂️ Handling asset request: $path")

return try {
    resolveBundledAssetFile(path, cleanPath)?.let { resolvedAsset ->
        return serveAssetFile(cleanPath, resolvedAsset.first, resolvedAsset.second)
    }

    if (isBinaryAssetPath(cleanPath)) {
        Log.w(TAG, "🚫 Binary asset missing from filesystem; refusing PHP fallback: $path")

        return errorResponse(404, "Binary asset not found: $path")
    }

    Log.d(TAG, "🔄 Asset not found in filesystem, trying PHP handler")

    val phpRequest = PHPRequest(
        url = "/$path",
        method = "GET",
        body = "",
        headers = mapOf("Accept" to "*/*"),
        getParameters = emptyMap()
    )

    val response = phpBridge.handleLaravelRequest(phpRequest)
    val (responseHeaders, body, statusCode) = parseResponse(response)
    Log.d(TAG, "RESPONSE HEADERS: ${responseHeaders}")

    if (statusCode == 200) {
        Log.d(TAG, "✅ Asset served via PHP: ${responseHeaders["Content-Type"]}")
        WebResourceResponse(
            responseHeaders["Content-Type"] ?: guessMimeType(cleanPath),
            responseHeaders["Charset"] ?: "UTF-8",
            statusCode,
            "OK",
            responseHeaders,
            body.byteInputStream()
        )
    } else {
        Log.d(TAG, "❌ Asset not found via PHP: $path (Status: $statusCode)")
        errorResponse(404, "Asset not found: $path")
    }
} catch (e: Exception) {
    Log.e(TAG, "⚠️ Error loading asset: $path", e)
    errorResponse(500, "Error loading asset: ${e.message}")
}
KOTLIN;

        $serveAssetFileBody = <<<'KOTLIN'
Log.d(TAG, "✅ Found asset at: ${assetFile.absolutePath}")

val responseHeaders = mutableMapOf<String, String>()
responseHeaders["Content-Type"] = mimeType
responseHeaders["Cache-Control"] = "max-age=86400, public"

when {
    cleanPath.endsWith(".css") -> {
        Log.d(TAG, "📋 Serving CSS file")
        responseHeaders["Content-Type"] = "text/css"
    }
    cleanPath.endsWith(".js") -> {
        Log.d(TAG, "📋 Serving JavaScript file")
        responseHeaders["Content-Type"] = "application/javascript"
    }
    cleanPath.endsWith(".woff") || cleanPath.endsWith(".woff2") ||
        cleanPath.endsWith(".ttf") || cleanPath.endsWith(".eot") || cleanPath.endsWith(".otf") -> {
        Log.d(TAG, "📋 Serving font file")
        responseHeaders["Access-Control-Allow-Origin"] = "*"
    }
}

Log.d(TAG, "📋 Serving with MIME type: ${responseHeaders["Content-Type"]}")
responseHeaders["Content-Length"] = assetFile.length().toString()

val bufferedStream = BufferedInputStream(assetFile.inputStream(), 1024 * 1024)

return WebResourceResponse(
    responseHeaders["Content-Type"] ?: mimeType,
    "UTF-8",
    200,
    "OK",
    responseHeaders,
    bufferedStream
)
KOTLIN;

        $resolveBundledAssetFileBody = <<<'KOTLIN'
val laravelRootPath = phpBridge.getLaravelRootPath().trimEnd('/')
val possiblePaths = listOf(
    "$laravelRootPath/public/$path",
    "$laravelRootPath/public/$cleanPath",
    "$laravelRootPath/public/vendor/$cleanPath",
    "$laravelRootPath/public/build/$cleanPath",
    "$laravelRootPath/$path",
    "$laravelRootPath/$cleanPath",
)

Log.d(TAG, "🔍 Checking paths: ${possiblePaths.joinToString()}")

possiblePaths.firstOrNull { File(it).exists() }?.let { existingPath ->
    val directAssetFile = File(existingPath)

    if (directAssetFile.exists()) {
        return Pair(directAssetFile, guessMimeType(cleanPath))
    }
}

return resolveBundledQpcFontFile(cleanPath)
KOTLIN;

        $resolveBundledQpcFontFileBody = <<<'KOTLIN'
val match = Regex("^qpc-v2-fonts/(\\d+)\\.(?:ttf|woff2)$").matchEntire(cleanPath) ?: return null
val pageNumber = match.groupValues.getOrNull(1)?.toIntOrNull() ?: return null

if (pageNumber !in 1..604) {
    return null
}

val laravelRootPath = phpBridge.getLaravelRootPath().trimEnd('/')
val candidatePaths = listOf(
    "$laravelRootPath/resources/raw-data/quran/fonts/qpc-v2/p$pageNumber.woff2",
    "$laravelRootPath/../resources/raw-data/quran/fonts/qpc-v2/p$pageNumber.woff2",
    "$laravelRootPath/vendor/goodm4ven/arabicable/resources/raw-data/quran/fonts/qpc-v2/p$pageNumber.woff2",
    "$laravelRootPath/resources/raw-data/quran/fonts/qpc-v2/p$pageNumber.ttf",
    "$laravelRootPath/../resources/raw-data/quran/fonts/qpc-v2/p$pageNumber.ttf",
    "$laravelRootPath/vendor/goodm4ven/arabicable/resources/raw-data/quran/fonts/qpc-v2/p$pageNumber.ttf",
)

candidatePaths.firstOrNull { File(it).exists() }?.let { existingPath ->
    val fontFile = File(existingPath)
    val mimeType = guessMimeType(fontFile.name)

    Log.d(TAG, "🕋 Resolved QPC page font directly from bundle: ${fontFile.absolutePath}")

    return Pair(fontFile, mimeType)
}

return null
KOTLIN;

        $isBinaryAssetPathBody = <<<'KOTLIN'
val normalizedPath = cleanPath.lowercase()

return listOf(
    ".png",
    ".jpg",
    ".jpeg",
    ".gif",
    ".svg",
    ".webp",
    ".ico",
    ".woff",
    ".woff2",
    ".ttf",
    ".eot",
    ".otf",
    ".pdf",
    ".zip",
    ".mp3",
    ".mp4",
    ".wav",
).any { normalizedPath.endsWith(it) }
KOTLIN;

        [$text, $updated] = $this->setKotlinFunctionBody($text, 'handleAssetRequest', $handleAssetRequestBody);
        $changed = $changed || $updated;

        if (str_contains($text, 'private fun serveAssetFile(')) {
            [$text, $updated] = $this->setKotlinFunctionBody($text, 'serveAssetFile', $serveAssetFileBody);
            $changed = $changed || $updated;
        } else {
            $serveAssetFileDefinition = <<<'KOTLIN'
    private fun serveAssetFile(cleanPath: String, assetFile: File, mimeType: String): WebResourceResponse {
KOTLIN;
            $serveAssetFileDefinition .= "\n".$this->indentKotlinBody($serveAssetFileBody, '        ')."\n    }\n";
            $changed = $this->insertBeforeOrError(
                $text,
                '    fun handlePHPRequest(',
                rtrim($serveAssetFileDefinition, "\n"),
                'serveAssetFile definition',
            ) || $changed;
        }

        if (str_contains($text, 'private fun resolveBundledAssetFile(')) {
            [$text, $updated] = $this->setKotlinFunctionBody($text, 'resolveBundledAssetFile', $resolveBundledAssetFileBody);
            $changed = $changed || $updated;
        } else {
            $resolveBundledAssetFileDefinition = <<<'KOTLIN'
    private fun resolveBundledAssetFile(path: String, cleanPath: String): Pair<File, String>? {
KOTLIN;
            $resolveBundledAssetFileDefinition .= "\n".$this->indentKotlinBody($resolveBundledAssetFileBody, '        ')."\n    }\n";
            $changed = $this->insertBeforeOrError(
                $text,
                '    fun handlePHPRequest(',
                rtrim($resolveBundledAssetFileDefinition, "\n"),
                'resolveBundledAssetFile definition',
            ) || $changed;
        }

        if (str_contains($text, 'private fun resolveBundledQpcFontFile(')) {
            [$text, $updated] = $this->setKotlinFunctionBody($text, 'resolveBundledQpcFontFile', $resolveBundledQpcFontFileBody);
            $changed = $changed || $updated;
        } else {
            $resolveBundledQpcFontFileDefinition = <<<'KOTLIN'
    private fun resolveBundledQpcFontFile(cleanPath: String): Pair<File, String>? {
KOTLIN;
            $resolveBundledQpcFontFileDefinition .= "\n".$this->indentKotlinBody($resolveBundledQpcFontFileBody, '        ')."\n    }\n";
            $changed = $this->insertBeforeOrError(
                $text,
                '    fun handlePHPRequest(',
                rtrim($resolveBundledQpcFontFileDefinition, "\n"),
                'resolveBundledQpcFontFile definition',
            ) || $changed;
        }

        if (str_contains($text, 'private fun isBinaryAssetPath(')) {
            [$text, $updated] = $this->setKotlinFunctionBody($text, 'isBinaryAssetPath', $isBinaryAssetPathBody);
            $changed = $changed || $updated;
        } else {
            $isBinaryAssetPathDefinition = <<<'KOTLIN'
    private fun isBinaryAssetPath(cleanPath: String): Boolean {
KOTLIN;
            $isBinaryAssetPathDefinition .= "\n".$this->indentKotlinBody($isBinaryAssetPathBody, '        ')."\n    }\n";
            $changed = $this->insertBeforeOrError(
                $text,
                '    fun handlePHPRequest(',
                rtrim($isBinaryAssetPathDefinition, "\n"),
                'isBinaryAssetPath definition',
            ) || $changed;
        }

        $this->writePatchResult($path, $text, $changed, 'native-android-assets');
    }

    private function indentKotlinBody(string $body, string $indent): string
    {
        $lines = explode("\n", $body);

        return implode("\n", array_map(
            static fn (string $line): string => $line === '' ? '' : $indent.$line,
            $lines,
        ));
    }
}
