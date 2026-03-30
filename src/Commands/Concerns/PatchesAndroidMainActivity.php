<?php

declare(strict_types=1);

namespace Goodm4ven\NativePatches\Commands\Concerns;

use RuntimeException;

trait PatchesAndroidMainActivity
{
    private function patchMainActivity(string $path): void
    {
        if (! file_exists($path)) {
            $this->info("[native-system-ui] skip missing: {$path}");

            return;
        }

        $text = file_get_contents($path);
        if ($text === false) {
            throw new RuntimeException("[native-system-ui] error: unable to read {$path}");
        }

        $changed = false;

        $changed = false;

        $fieldPattern = '/(    private var pendingInsets: Insets\? = null\n)(?!    private var lastStableInsets: Insets\? = null\n)/m';
        if (preg_match($fieldPattern, $text) === 1) {
            $text = preg_replace($fieldPattern, "$1    private var lastStableInsets: Insets? = null\n", $text, 1);
            $changed = true;
        } elseif (! str_contains($text, "    private var lastStableInsets: Insets? = null\n")) {
            throw new RuntimeException('[native-system-ui] error: pattern not found for lastStableInsets field');
        }

        $lifecycleFlagPattern = '/(    private var shouldStopWatcher = false\n)(?!    @Volatile\n    private var isMainActivityDestroyed = false\n)/m';
        if (preg_match($lifecycleFlagPattern, $text) === 1) {
            $text = preg_replace(
                $lifecycleFlagPattern,
                "    private var shouldStopWatcher = false\n    @Volatile\n    private var isMainActivityDestroyed = false\n",
                $text,
                1,
            );
            $changed = true;
        } elseif (! str_contains($text, "    private var isMainActivityDestroyed = false\n")) {
            throw new RuntimeException('[native-system-ui] error: pattern not found for lifecycle destroyed flag');
        }

        $companionOriginal = <<<'KOTLIN'
    companion object {
        // Static instance holder for accessing MainActivity from other activities
        var instance: MainActivity? = null
            private set
    }
KOTLIN;
        $companionReplacement = <<<'KOTLIN'
    companion object {
        // Static instance holder for accessing MainActivity from other activities
        var instance: MainActivity? = null
            private set
        private val environmentInitMonitor = Any()
        private var environmentInitInProgress = false
    }
KOTLIN;

        $changed = $this->replaceOnceOrError(
            $text,
            $companionOriginal,
            $companionReplacement,
            'MainActivity companion init lock',
            '        private var environmentInitInProgress = false',
        ) || $changed;

        $stableInsetPattern = '/(            pendingInsets = systemBars\n)(?!            if \(systemBars.top > 0 \|\| systemBars.bottom > 0 \|\| systemBars.left > 0 \|\| systemBars.right > 0\) \{\n                lastStableInsets = systemBars\n            \}\n)/m';
        if (preg_match($stableInsetPattern, $text) === 1) {
            $text = preg_replace(
                $stableInsetPattern,
                "            pendingInsets = systemBars\n            if (systemBars.top > 0 || systemBars.bottom > 0 || systemBars.left > 0 || systemBars.right > 0) {\n                lastStableInsets = systemBars\n            }\n",
                $text,
                1,
            );
            $changed = true;
        } elseif (! str_contains($text, "            if (systemBars.top > 0 || systemBars.bottom > 0 || systemBars.left > 0 || systemBars.right > 0) {\n                lastStableInsets = systemBars\n            }\n")) {
            throw new RuntimeException('[native-system-ui] error: pattern not found for stable inset capture');
        }

        $cssOldSimple = <<<'KOTLIN'
            // Inject CSS custom properties into WebView if ready
            if (::webViewManager.isInitialized) {
                injectSafeAreaInsets(systemBars.left, systemBars.top, systemBars.right, systemBars.bottom)
            }
KOTLIN;
        $cssOldFallback = <<<'KOTLIN'
            // Inject CSS custom properties into WebView if ready
            if (::webViewManager.isInitialized) {
                val isZeroInsets = systemBars.top == 0 && systemBars.bottom == 0 && systemBars.left == 0 && systemBars.right == 0
                val effectiveInsets = if (isZeroInsets) (lastStableInsets ?: systemBars) else systemBars
                injectSafeAreaInsets(effectiveInsets.left, effectiveInsets.top, effectiveInsets.right, effectiveInsets.bottom)
            }
KOTLIN;
        $cssNew = "            // Safe area handled by Compose insets\n";

        if (str_contains($text, $cssOldSimple)) {
            $text = $this->replaceFirst($text, $cssOldSimple, $cssNew);
            $changed = true;
        } elseif (str_contains($text, $cssOldFallback)) {
            $text = $this->replaceFirst($text, $cssOldFallback, $cssNew);
            $changed = true;
        } elseif (! str_contains($text, $cssNew)) {
            throw new RuntimeException('[native-system-ui] error: pattern not found for safe-area insets listener replacement');
        }

        $changed = $this->replaceOnceOrError(
            $text,
            "            // Inject safe area insets BEFORE loading any URL to prevent content shift\n            pendingInsets?.let {\n                injectSafeAreaInsets(it.left, it.top, it.right, it.bottom)\n            }\n",
            "            // Inject safe area insets BEFORE loading any URL to prevent content shift\n            injectSafeAreaInsetsToWebView()\n",
            'startup safe-area call',
            '            injectSafeAreaInsetsToWebView()',
        ) || $changed;

        $injectSafeAreaBody = <<<'KOTLIN'
Log.d(
    "SafeArea",
    "Safe area handled by Compose layout insets"
)
KOTLIN;

        [$text, $updated] = $this->setKotlinFunctionBody($text, 'injectSafeAreaInsets', $injectSafeAreaBody);
        $changed = $changed || $updated;

        [$text, $updated] = $this->setKotlinFunctionBody($text, 'injectSafeAreaInsetsToWebView', $injectSafeAreaBody);
        $changed = $changed || $updated;

        $initializeEnvironmentBody = <<<'KOTLIN'
Thread {
    Log.d("LaravelInit", "📦 Starting async Laravel extraction...")
    if (isMainActivityDestroyed) {
        Log.w("LaravelInit", "Skipping environment init because activity is already destroyed")
        return@Thread
    }

    var acquiredInitSlot = false
    while (!acquiredInitSlot) {
        if (isMainActivityDestroyed) {
            Log.w("LaravelInit", "Skipping environment init because activity is already destroyed")
            return@Thread
        }

        synchronized(environmentInitMonitor) {
            if (!environmentInitInProgress) {
                environmentInitInProgress = true
                acquiredInitSlot = true
            }
        }

        if (!acquiredInitSlot) {
            try {
                Thread.sleep(50)
            } catch (e: InterruptedException) {
                Log.w("LaravelInit", "Environment init wait interrupted")
                return@Thread
            }
        }
    }

    try {
        laravelEnv = LaravelEnvironment(this)
        laravelEnv.initialize()

        if (isMainActivityDestroyed) {
            Log.w("LaravelInit", "Skipping onReady callback because activity was destroyed during init")
            return@Thread
        }

        Log.d("LaravelInit", "✅ Laravel environment ready — continuing")

        Handler(Looper.getMainLooper()).post {
            if (isMainActivityDestroyed || isFinishing || isDestroyed || supportFragmentManager.isDestroyed) {
                Log.w("LaravelInit", "Skipping onReady callback because activity is no longer valid")
                return@post
            }

            onReady()
        }
    } finally {
        synchronized(environmentInitMonitor) {
            environmentInitInProgress = false
        }
    }
}.start()
KOTLIN;

        [$text, $updated] = $this->setKotlinFunctionBody($text, 'initializeEnvironmentAsync', $initializeEnvironmentBody);
        $changed = $changed || $updated;

        $onDestroyBody = <<<'KOTLIN'
isMainActivityDestroyed = true
super.onDestroy()
instance = null

// Post lifecycle event for plugins
NativePHPLifecycle.post(NativePHPLifecycle.Events.ON_DESTROY)

// Clean up coordinator fragment to prevent memory leaks
if (::coord.isInitialized && !supportFragmentManager.isDestroyed) {
    supportFragmentManager.beginTransaction()
        .remove(coord)
        .commitNowAllowingStateLoss()
}

if (::webViewManager.isInitialized) {
    val chromeClient = webView.webChromeClient
    if (chromeClient is WebChromeClient) {
        chromeClient.onHideCustomView()
    }
}

// Stop hot reload watcher thread
shouldStopWatcher = true
hotReloadWatcherThread?.interrupt()

if (::laravelEnv.isInitialized) {
    laravelEnv.cleanup()
}
phpBridge.shutdown()
KOTLIN;

        [$text, $updated] = $this->setKotlinFunctionBody($text, 'onDestroy', $onDestroyBody);
        $changed = $changed || $updated;

        $hotReloadOld = "            var lastModified: Long = 0\n";
        $hotReloadNew = "            var lastModified: Long = if (reloadFile.exists()) reloadFile.lastModified() else 0\n";

        $changed = $this->replaceOnceOrError(
            $text,
            $hotReloadOld,
            $hotReloadNew,
            'hot reload lastModified init',
            $hotReloadNew,
        ) || $changed;

        $changed = $this->replaceRegexOnceOrError(
            $text,
            '(            settings\.mediaPlaybackRequiresUserGesture = false\n)(?!            isSaveEnabled = false\n)',
            "$1            isSaveEnabled = false\n",
            'WebView state saving',
            '            isSaveEnabled = false',
        ) || $changed;

        $changed = $this->replaceRegexOnceOrError(
            $text,
            <<<'REGEX'
                        AndroidView\(\n\s+factory = \{ webView \},\n\s+modifier = Modifier\n\s+\.fillMaxSize\(\)\n\s+\.padding\(paddingValues\)\n(\s+\.consumeWindowInsets\(paddingValues\)\n)?\s+\.windowInsetsPadding\(WindowInsets\.ime\),\n
REGEX,
            "                        AndroidView(\n                            factory = { webView },\n                            modifier = Modifier\n                                .fillMaxSize()\n                                .padding(paddingValues)\n$1                                .windowInsetsPadding(WindowInsets.systemBars)\n                                .windowInsetsPadding(WindowInsets.ime),\n",
            'AndroidView system bars inset',
            '                                .windowInsetsPadding(WindowInsets.systemBars)',
        ) || $changed;

        $changed = $this->insertBeforeOrError(
            $text,
            '            // Splash overlay with fade animation (full screen, no insets)',
            '            SystemBarsScrim()',
            'SystemBarsScrim call',
        ) || $changed;

        $systemBarsScrimBody = <<<'KOTLIN'
val systemInDarkMode = isSystemInDarkTheme()
val barColor = if (systemInDarkMode) Color.Black else Color.White
val statusBarHeight = WindowInsets.statusBars.asPaddingValues().calculateTopPadding()
val navigationBarHeight = WindowInsets.navigationBars.asPaddingValues().calculateBottomPadding()
val extraBottom = 14.dp
val scrimBottomHeight = if (navigationBarHeight > 0.dp) {
    navigationBarHeight + extraBottom
} else {
    0.dp
}

Box(modifier = Modifier.fillMaxSize()) {
    if (statusBarHeight > 0.dp) {
        Box(
            modifier = Modifier
                .fillMaxWidth()
                .height(statusBarHeight)
                .background(barColor)
        )
    }
    if (scrimBottomHeight > 0.dp) {
        Box(
            modifier = Modifier
                .fillMaxWidth()
                .height(scrimBottomHeight)
                .align(Alignment.BottomCenter)
                .background(barColor)
        )
    }
}
KOTLIN;

        if (str_contains($text, 'private fun SystemBarsScrim()')) {
            [$text, $updated] = $this->setKotlinFunctionBody($text, 'SystemBarsScrim', $systemBarsScrimBody);
            $changed = $changed || $updated;
        } else {
            $definition = $this->buildComposableDefinition('SystemBarsScrim', $systemBarsScrimBody);
            $changed = $this->insertBeforeOrError(
                $text,
                "    /**\n     * Splash screen composable - shows custom image or fallback text\n     */",
                rtrim($definition, "\n"),
                'SystemBarsScrim definition',
            ) || $changed;
        }

        $this->writePatchResult($path, $text, $changed, 'native-system-ui');

        $text = file_get_contents($path);
        if ($text === false) {
            throw new RuntimeException("[native-back-handler] error: unable to read {$path}");
        }

        $changed = false;
        if (str_contains($text, 'window.__nativeBackAction')) {
            $this->info("[native-back-handler] already ok: {$path}");
        } else {
            $originalBlock = <<<'KOTLIN'
        onBackPressedDispatcher.addCallback(this) {
            if (webView.canGoBack()) {
                webView.goBack()
            } else {
                finish()
            }
        }
KOTLIN;

            $newBlock = <<<'KOTLIN'
        onBackPressedDispatcher.addCallback(this) {
            val js =
                "(function() { try { return window.__nativeBackAction && window.__nativeBackAction(); } " +
                    "catch (e) { return false; } })();"

            webView.evaluateJavascript(js) { value ->
                val normalized = value?.trim()?.trim('\"')
                val handled = normalized == "true"
                val shouldExit = normalized == "exit"
                if (handled) {
                    return@evaluateJavascript
                }

                if (shouldExit) {
                    finish()
                    return@evaluateJavascript
                }

                if (webView.canGoBack()) {
                    webView.goBack()
                } else {
                    finish()
                }
            }
        }
KOTLIN;

            if (! str_contains($text, $originalBlock)) {
                throw new RuntimeException("[native-back-handler] error: expected onBackPressed block not found in {$path}");
            }

            $text = $this->replaceFirst($text, $originalBlock, $newBlock);
            $changed = true;
            $this->writePatchResult($path, $text, $changed, 'native-back-handler');
        }

        $text = file_get_contents($path);
        if ($text === false) {
            throw new RuntimeException("[native-google-reviews] error: unable to read {$path}");
        }

        $changed = false;
        $changed = $this->insertImport(
            $text,
            'import androidx.activity.enableEdgeToEdge',
            'import androidx.activity.addCallback',
            'import androidx.activity.enableEdgeToEdge',
        );

        $edgeToEdgeOld = <<<'KOTLIN'
        // Android 15 edge-to-edge compatibility fix
        WindowCompat.setDecorFitsSystemWindows(window, false)
KOTLIN;
        $edgeToEdgeNew = <<<'KOTLIN'
        // Android 15 edge-to-edge compatibility (and pre-35 backport)
        enableEdgeToEdge()
KOTLIN;

        $changed = $this->replaceOnceOrError(
            $text,
            $edgeToEdgeOld,
            $edgeToEdgeNew,
            'edge-to-edge setup',
            'enableEdgeToEdge()',
        ) || $changed;

        if (! str_contains($text, 'WindowCompat.setDecorFitsSystemWindows')) {
            $changed = $this->removeLine(
                $text,
                "import androidx.core.view.WindowCompat\n",
            ) || $changed;
        }

        $changed = $this->removeLine(
            $text,
            "    @Suppress(\"DEPRECATION\")\n",
        ) || $changed;

        $finalConfigureStatusBarDocstring = '     * For edge-to-edge mode, we rely on the SystemBarsScrim for background and only set icon contrast'."\n";

        $updatedConfigureStatusBarDocstring = $this->replaceOnceOrError(
            $text,
            '     * For edge-to-edge mode, system bars are transparent to allow content to draw behind them'."\n",
            $finalConfigureStatusBarDocstring,
            'configureStatusBar docstring',
            $finalConfigureStatusBarDocstring,
        );

        if (! $updatedConfigureStatusBarDocstring) {
            $updatedConfigureStatusBarDocstring = $this->replaceOnceOrError(
                $text,
                '     * For edge-to-edge mode, system bars follow the system light/dark theme so content does not bleed through'."\n",
                $finalConfigureStatusBarDocstring,
                'configureStatusBar docstring',
                $finalConfigureStatusBarDocstring,
            );
        }

        $changed = $updatedConfigureStatusBarDocstring || $changed;

        $configureStatusBarBody = <<<'KOTLIN'
val windowInsetsController = WindowInsetsControllerCompat(window, window.decorView)

val isSystemDarkMode = (resources.configuration.uiMode and
    Configuration.UI_MODE_NIGHT_MASK) == Configuration.UI_MODE_NIGHT_YES

// Match system light/dark for icon contrast
windowInsetsController.isAppearanceLightStatusBars = !isSystemDarkMode
windowInsetsController.isAppearanceLightNavigationBars = !isSystemDarkMode

Log.d(
    "StatusBar",
    "System bars style: auto (system ${if (isSystemDarkMode) "dark" else "light"} mode, requested=$statusBarStyle)"
)
KOTLIN;

        [$text, $updated] = $this->setKotlinFunctionBody($text, 'configureStatusBar', $configureStatusBarBody);
        $changed = $changed || $updated;

        if ($changed) {
            file_put_contents($path, $text);
            $this->info("[native-google-reviews] patched: {$path}");
        } else {
            $this->info("[native-google-reviews] already ok: {$path}");
        }

        if (str_contains($text, 'statusBarColor') || str_contains($text, 'navigationBarColor')) {
            $this->info("[native-google-reviews] warning: status/navigation bar color setters still present in {$path}");
        }
    }
}
