<?php

declare(strict_types=1);

namespace Goodm4ven\NativePatches\Commands;

use Native\Mobile\Plugins\Commands\NativePluginHookCommand;
use RuntimeException;

class ApplyNativePatchesCommand extends NativePluginHookCommand
{
    protected $signature = 'nativephp:muttasiq:patches';

    public function handle(): int
    {
        if (! $this->isAndroid()) {
            $this->info('Skipping Muttasiq native patches (non-Android build).');

            return self::SUCCESS;
        }

        $buildPath = $this->buildPath();
        if ($buildPath === '' || ! is_dir($buildPath)) {
            $this->error('Android build path is missing; cannot apply patches.');

            return self::FAILURE;
        }

        $hadErrors = false;

        try {
            $this->patchEdgeComponents(base_path());
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            $hadErrors = true;
        }

        $mainActivityPath = $buildPath.'/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt';
        try {
            $this->patchMainActivity($mainActivityPath);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            $hadErrors = true;
        }

        $webViewManagerPath = $buildPath.'/app/src/main/java/com/nativephp/mobile/network/WebViewManager.kt';
        try {
            $this->patchWebViewManager($webViewManagerPath);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            $hadErrors = true;
        }

        return $hadErrors ? self::FAILURE : self::SUCCESS;
    }

    private function patchEdgeComponents(string $basePath): void
    {
        $topBarPath = $basePath.'/vendor/nativephp/mobile/src/Edge/Components/Navigation/TopBar.php';
        $bottomNavPath = $basePath.'/vendor/nativephp/mobile/src/Edge/Components/Navigation/BottomNav.php';
        $edgePath = $basePath.'/vendor/nativephp/mobile/src/Edge/Edge.php';

        $this->patchTopBarComponent($topBarPath);
        $this->patchBottomNavComponent($bottomNavPath);
        $this->patchEdgeComponent($edgePath);
    }

    private function patchTopBarComponent(string $path): void
    {
        if (! file_exists($path)) {
            $this->info("[native-edge] skip missing: {$path}");

            return;
        }

        $text = file_get_contents($path);
        if ($text === false) {
            throw new RuntimeException("[native-edge] error: unable to read {$path}");
        }

        $changed = false;
        $changed = $this->replaceOnceOrError(
            $text,
            'public bool $showNavigationIcon = true,',
            'public ?bool $showNavigationIcon = null,',
            'TopBar showNavigationIcon default',
            'public ?bool $showNavigationIcon = null,',
        ) || $changed;

        $changed = $this->replaceOnceOrError(
            $text,
            'fn ($value) => $value !== null && $value !== false',
            'fn ($value) => $value !== null',
            'TopBar array_filter predicate',
            'fn ($value) => $value !== null',
        ) || $changed;

        $this->writePatchResult($path, $text, $changed, 'native-edge');
    }

    private function patchBottomNavComponent(string $path): void
    {
        if (! file_exists($path)) {
            $this->info("[native-edge] skip missing: {$path}");

            return;
        }

        $text = file_get_contents($path);
        if ($text === false) {
            throw new RuntimeException("[native-edge] error: unable to read {$path}");
        }

        $changed = false;
        $changed = $this->replaceOnceOrError(
            $text,
            "public string \$labelVisibility = 'labeled',",
            'public ?string $labelVisibility = null,',
            'BottomNav labelVisibility default',
            'public ?string $labelVisibility = null,',
        ) || $changed;

        $patchedMethod = <<<'PHP'
protected function toNativeProps(): array
    {
        return array_filter([
            'dark' => $this->dark,
            'label_visibility' => $this->labelVisibility,
            'active_color' => $this->activeColor,
        ], fn ($value) => $value !== null);
    }
PHP;

        if (! str_contains($text, $patchedMethod)) {
            $methodPatternWithActiveColor = <<<'REGEX'
protected function toNativeProps\(\): array\s*\{\s*return \[\s*'dark' => \$this->dark,\s*'label_visibility' => \$this->labelVisibility,\s*'active_color' => \$this->activeColor,\s*'id' => 'bottom_nav',\s*\];\s*\}
REGEX;
            $methodPatternLegacy = <<<'REGEX'
protected function toNativeProps\(\): array\s*\{\s*return \[\s*'dark' => \$this->dark,\s*'label_visibility' => \$this->labelVisibility,\s*'id' => 'bottom_nav',\s*\];\s*\}
REGEX;

            $updated = $this->replaceRegexOnceOrError(
                $text,
                $methodPatternWithActiveColor,
                $patchedMethod,
                'BottomNav toNativeProps (active_color)',
                $patchedMethod,
            );

            if (! $updated && ! str_contains($text, $patchedMethod)) {
                $updated = $this->replaceRegexOnceOrError(
                    $text,
                    $methodPatternLegacy,
                    $patchedMethod,
                    'BottomNav toNativeProps (legacy)',
                    $patchedMethod,
                );
            }

            $changed = $changed || $updated;
        }

        $this->writePatchResult($path, $text, $changed, 'native-edge');
    }

    private function patchEdgeComponent(string $path): void
    {
        if (! file_exists($path)) {
            $this->info("[native-edge] skip missing: {$path}");

            return;
        }

        $text = file_get_contents($path);
        if ($text === false) {
            throw new RuntimeException("[native-edge] error: unable to read {$path}");
        }

        $changed = false;

        $needle = "        \$target = &self::navigateToComponent(\$context);\n\n        // Update the placeholder with actual data\n";
        $replacement = <<<'PHP'
        $target = &self::navigateToComponent($context);
        $children = $target['data']['children'] ?? [];

        $shouldSkip = false;
        if ($type === 'top_bar') {
            $title = $data['title'] ?? null;
            if ($title === null || $title === '') {
                $shouldSkip = true;
            }
        } elseif ($type === 'bottom_nav') {
            if (empty($data) && empty($children)) {
                $shouldSkip = true;
            }
        }

        if ($shouldSkip) {
            if (count($context) === 1) {
                unset(self::$components[$context[0]]);
                self::$components = array_values(self::$components);
            } else {
                $childIndex = array_pop($context);
                array_pop($context);
                array_pop($context);
                $parent = &self::navigateToComponent($context);
                if (isset($parent['data']['children'][$childIndex])) {
                    unset($parent['data']['children'][$childIndex]);
                    $parent['data']['children'] = array_values($parent['data']['children']);
                }
            }

            array_pop(self::$contextStack);
            return;
        }

        // Update the placeholder with actual data
PHP;

        if (str_contains($text, $replacement)) {
            $updated = false;
        } elseif (str_contains($text, $needle)) {
            $text = $this->replaceFirst($text, $needle, $replacement);
            $updated = true;
        } else {
            throw new RuntimeException('[native-edge] error: pattern not found for Edge context skip logic');
        }

        $changed = $changed || $updated;

        $changed = $this->replaceOnceOrError(
            $text,
            "        \$target['data'] = array_merge(\$data, [\n            'children' => \$target['data']['children'] ?? [],\n        ]);\n",
            "        \$target['data'] = array_merge(\$data, [\n            'children' => \$children,\n        ]);\n",
            'Edge children assignment',
            "            'children' => \$children,",
        ) || $changed;

        $this->writePatchResult($path, $text, $changed, 'native-edge');
    }

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

        $systemUiBody = <<<'KOTLIN'
val windowInsetsController = WindowInsetsControllerCompat(window, window.decorView)

val isSystemDarkMode = (resources.configuration.uiMode and
    Configuration.UI_MODE_NIGHT_MASK) == Configuration.UI_MODE_NIGHT_YES

val systemBarColor = if (isSystemDarkMode) {
    android.graphics.Color.BLACK
} else {
    android.graphics.Color.WHITE
}

// Use opaque, system-themed bars so web content doesn't bleed into system UI
window.statusBarColor = systemBarColor
window.navigationBarColor = systemBarColor

// Always match system light/dark for icon contrast
windowInsetsController.isAppearanceLightStatusBars = !isSystemDarkMode
windowInsetsController.isAppearanceLightNavigationBars = !isSystemDarkMode

Log.d(
    "StatusBar",
    "System bars style: auto (system ${if (isSystemDarkMode) "dark" else "light"} mode, requested=$statusBarStyle)"
)
KOTLIN;

        [$text, $updated] = $this->setKotlinFunctionBody($text, 'configureStatusBar', $systemUiBody);
        $changed = $changed || $updated;

        $changed = $this->replaceOnceOrError(
            $text,
            'For edge-to-edge mode, system bars are transparent to allow content to draw behind them',
            'For edge-to-edge mode, system bars follow the system light/dark theme so content does not bleed through',
            'status bar docstring',
            'SystemBarsScrim',
        ) || $changed;

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

        $changed = $this->replaceOnceOrError(
            $text,
            "            settings.mediaPlaybackRequiresUserGesture = false\n",
            "            settings.mediaPlaybackRequiresUserGesture = false\n            isSaveEnabled = false\n",
            'WebView state saving',
            '            isSaveEnabled = false',
        ) || $changed;

        $changed = $this->replaceOnceOrError(
            $text,
            "                        AndroidView(\n                            factory = { webView },\n                            modifier = Modifier\n                                .fillMaxSize()\n                                .padding(paddingValues)\n                                .windowInsetsPadding(WindowInsets.ime),\n",
            "                        AndroidView(\n                            factory = { webView },\n                            modifier = Modifier\n                                .fillMaxSize()\n                                .padding(paddingValues)\n                                .windowInsetsPadding(WindowInsets.systemBars)\n                                .windowInsetsPadding(WindowInsets.ime),\n",
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

        if (str_contains($text, 'android.graphics.Color.TRANSPARENT')) {
            $this->info("[native-system-ui] warning: transparent system bars still present in {$path}");
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
        ) || $changed;

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

        $changed = $this->replaceOnceOrError(
            $text,
            '     * For edge-to-edge mode, system bars follow the system light/dark theme so content does not bleed through\n',
            '     * For edge-to-edge mode, we rely on the SystemBarsScrim for background and only set icon contrast\n',
            'configureStatusBar docstring',
            'SystemBarsScrim',
        ) || $changed;

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


    private function patchWebViewManager(string $path): void
    {
        if (! file_exists($path)) {
            $this->info("[native-request-capture] skip missing: {$path}");

            return;
        }

        $text = file_get_contents($path);
        if ($text === false) {
            throw new RuntimeException("[native-request-capture] error: unable to read {$path}");
        }

        $changed = false;

        $cleanupSnippets = [
            "import com.nativephp.mobile.bridge.LaravelEnvironment
",
            "import java.io.ByteArrayInputStream
",
            <<<'KOTLIN'
                val livewirePath = request.url.path ?: ""
                val isLivewireUpdate = livewirePath.startsWith("/livewire") && livewirePath.endsWith("/update")
                if (request.isForMainFrame && isLivewireUpdate) {
                    Log.w(TAG, "🚫 Blocking main-frame Livewire update navigation: $url")
                    val target = LaravelEnvironment.getStartURL(context)
                    view.loadUrl("http://127.0.0.1$target")
                    return true
                }
KOTLIN,
            <<<'KOTLIN'
                if (request.isForMainFrame && request.url.path == "/livewire/update") {
                    Log.w(TAG, "🚫 Blocking main-frame Livewire update navigation: $url")
                    val target = LaravelEnvironment.getStartURL(context)
                    view.loadUrl("http://127.0.0.1$target")
                    return true
                }
KOTLIN,
            <<<'KOTLIN'
val livewirePath = request.url.path ?: ""
val isLivewireUpdate = livewirePath.startsWith("/livewire") && livewirePath.endsWith("/update")
val hasLivewireHeader = request.requestHeaders.keys.any { it.equals("X-Livewire", ignoreCase = true) }
val contentType = request.requestHeaders.entries.firstOrNull {
    it.key.equals("Content-Type", ignoreCase = true)
}?.value ?: ""
val postData = phpBridge.getLastPostData()
val isMalformedLivewireUpdate = isLivewireUpdate && (
    request.isForMainFrame ||
        method.uppercase() != "POST" ||
        !hasLivewireHeader ||
        !contentType.contains("application/json", ignoreCase = true) ||
        postData.isNullOrBlank()
)
if (isMalformedLivewireUpdate) {
    Log.w(TAG, "🚫 Blocking malformed Livewire update request: $url")

    if (request.isForMainFrame) {
        val target = LaravelEnvironment.getStartURL(context)
        val html = """
            <!doctype html>
            <html><head>
                <meta http-equiv="refresh" content="0;url=http://127.0.0.1$target">
            </head><body></body></html>
        """.trimIndent()
        return WebResourceResponse("text/html", "UTF-8", html.byteInputStream())
    }

    return WebResourceResponse(
        "application/json",
        "UTF-8",
        200,
        "OK",
        mapOf("Cache-Control" to "no-store"),
        ByteArrayInputStream("""{"components":[],"assets":{}}""".toByteArray())
    )
}
KOTLIN,
            <<<'KOTLIN'
val livewirePath = request.url.path ?: ""
if (request.isForMainFrame && livewirePath.startsWith("/livewire") && livewirePath.endsWith("/update")) {
    Log.w(TAG, "🚫 Blocking main-frame Livewire update request: $url")
    val target = LaravelEnvironment.getStartURL(context)
    val html = """
        <!doctype html>
        <html><head>
            <meta http-equiv="refresh" content="0;url=http://127.0.0.1$target">
        </head><body></body></html>
    """.trimIndent()
    return WebResourceResponse("text/html", "UTF-8", html.byteInputStream())
}
KOTLIN,
        ];

        foreach ($cleanupSnippets as $snippet) {
            if (! str_contains($text, $snippet)) {
                continue;
            }

            $text = str_replace($snippet, '', $text);
            $changed = true;
        }

        $changed = $this->insertImport(
            $text,
            'import androidx.webkit.WebViewCompat',
            'import android.app.Activity',
            'WebViewCompat import',
        ) || $changed;

        $changed = $this->insertImport(
            $text,
            'import androidx.webkit.WebViewFeature',
            'import androidx.webkit.WebViewCompat',
            'WebViewFeature import',
        ) || $changed;

        $fieldPattern = '/(    private var customViewCallback: WebChromeClient\.CustomViewCallback\? = null
)(?!    private var requestInterceptionInstalled = false
)/m';
        if (preg_match($fieldPattern, $text) === 1) {
            $text = preg_replace(
                $fieldPattern,
                "    private var customViewCallback: WebChromeClient.CustomViewCallback? = null
    private var requestInterceptionInstalled = false
",
                $text,
                1,
            );
            $changed = true;
        } elseif (! str_contains($text, "    private var requestInterceptionInstalled = false
")) {
            throw new RuntimeException('[native-request-capture] error: pattern not found for request interception field');
        }

        $changed = $this->replaceOnceOrError(
            $text,
            "        setupWebViewClient()
        setupJavaScriptInterfaces()
        WebViewManager.shared = this // 👈 make this instance globally accessible
",
            "        setupWebViewClient()
        setupJavaScriptInterfaces()
        installRequestInterception()
        WebViewManager.shared = this // 👈 make this instance globally accessible
",
            'WebViewManager setup request interception',
            '        installRequestInterception()',
        ) || $changed;

        $changed = $this->replaceOnceOrError(
            $text,
            "                // Inject JavaScript to capture form submissions and AJAX requests
                injectJavaScript(view)
",
            "                // Fall back to page-finished injection if document-start scripts are unavailable
                if (!WebViewFeature.isFeatureSupported(WebViewFeature.DOCUMENT_START_SCRIPT)) {
                    injectJavaScript(view)
                }
",
            'onPageFinished request interception fallback',
            'document-start scripts are unavailable',
        ) || $changed;

        $installRequestInterceptionBody = <<<'KOTLIN'
if (requestInterceptionInstalled) {
    return
}

if (!WebViewFeature.isFeatureSupported(WebViewFeature.DOCUMENT_START_SCRIPT)) {
    Log.d(TAG, "Document-start request interception unavailable; using page-finished fallback")
    return
}

WebViewCompat.addDocumentStartJavaScript(
    webView,
    requestCaptureJavaScript(),
    setOf("http://127.0.0.1")
)
requestInterceptionInstalled = true
Log.d(TAG, "Document-start request interception installed")
KOTLIN;

        $requestCaptureJavaScriptBody = <<<'KOTLIN'
return """
(function() {
    if (window.__nativePostInterceptionInstalled) {
        return "POST+PATCH+PUT interception already installed";
    }
    window.__nativePostInterceptionInstalled = true;

    // 🌐 Native event bridge
    const listeners = {};

    const Native = {
        on: function(eventName, callback) {
            if (!listeners[eventName]) {
                listeners[eventName] = [];
            }
            listeners[eventName].push(callback);
        },
        off: function(eventName, callback) {
            if (listeners[eventName]) {
                listeners[eventName] = listeners[eventName].filter(cb => cb !== callback);
            }
        },
        dispatch: function(eventName, payload) {
            const cbs = listeners[eventName] || [];
            cbs.forEach(cb => cb(payload, eventName));
        }
    };

    window.Native = Native;

    document.addEventListener("native-event", function(e) {
        const eventName = e.detail.event;
        const payload = e.detail.payload;

        window.Native.dispatch(eventName, payload);
    });

    function serializeBody(body) {
        if (body instanceof FormData) {
            const formObj = {};
            body.forEach(function(value, key) {
                formObj[key] = value;
            });
            return JSON.stringify(formObj);
        }

        if (typeof body === "string") {
            return body;
        }

        if (body && typeof body === "object") {
            try {
                return JSON.stringify(body);
            } catch (error) {
                return String(body);
            }
        }

        return body == null ? "" : String(body);
    }

    function headersToString(headers) {
        let headerString = "";

        if (!headers) {
            return headerString;
        }

        if (headers instanceof Headers) {
            headers.forEach(function(value, key) {
                headerString += key + ": " + value + "\n";
            });

            return headerString;
        }

        if (Array.isArray(headers)) {
            headers.forEach(function(entry) {
                if (Array.isArray(entry) && entry.length >= 2) {
                    headerString += entry[0] + ": " + entry[1] + "\n";
                }
            });

            return headerString;
        }

        Object.keys(headers).forEach(function(key) {
            headerString += key + ": " + headers[key] + "\n";
        });

        return headerString;
    }

    document.addEventListener("submit", function(e) {
        const form = e.target;
        const method = (form.method || "").toLowerCase();
        if (!["post", "patch", "put"].includes(method)) {
            return;
        }

        const formData = new FormData(form);
        const urlEncodedData = new URLSearchParams();
        for (const pair of formData.entries()) {
            urlEncodedData.append(pair[0], pair[1]);
        }

        AndroidPOST.logPostData(
            urlEncodedData.toString(),
            form.action,
            "Content-Type: application/x-www-form-urlencoded"
        );
    });

    const originalXHROpen = XMLHttpRequest.prototype.open;
    const originalXHRSend = XMLHttpRequest.prototype.send;
    const originalXHRSetRequestHeader = XMLHttpRequest.prototype.setRequestHeader;

    XMLHttpRequest.prototype.open = function(method, url) {
        this._method = method;
        this._url = url;
        this._nativeHeaders = {};
        return originalXHROpen.apply(this, arguments);
    };

    XMLHttpRequest.prototype.setRequestHeader = function(name, value) {
        this._nativeHeaders = this._nativeHeaders || {};
        this._nativeHeaders[name] = value;
        return originalXHRSetRequestHeader.apply(this, arguments);
    };

    XMLHttpRequest.prototype.send = function(data) {
        if (["post", "patch", "put"].includes((this._method || "").toLowerCase()) && data != null) {
            AndroidPOST.logPostData(
                serializeBody(data),
                this._url,
                headersToString(this._nativeHeaders)
            );
        }
        return originalXHRSend.apply(this, arguments);
    };

    const originalFetch = window.fetch;

    window.fetch = function(url, options) {
        if (options && options.method && ["post", "patch", "put"].includes(options.method.toLowerCase()) && options.body != null) {
            AndroidPOST.logPostData(
                serializeBody(options.body),
                url,
                headersToString(options.headers)
            );
        }
        return originalFetch.apply(this, arguments);
    };

    function findAndSendCsrfToken() {
        const tokenField = document.querySelector('input[name="_token"]');
        if (tokenField) {
            AndroidPOST.storeCsrfToken(tokenField.value);
            return;
        }

        if (window.livewire && window.livewire.csrfToken) {
            AndroidPOST.storeCsrfToken(window.livewire.csrfToken);
        }
    }

    function observeForCsrfToken() {
        if (!document.body) {
            document.addEventListener("DOMContentLoaded", observeForCsrfToken, { once: true });
            return;
        }

        const observer = new MutationObserver(function() {
            findAndSendCsrfToken();
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }

    findAndSendCsrfToken();
    observeForCsrfToken();

    return "POST+PATCH+PUT interception installed";
})();
""".trimIndent()
KOTLIN;

        $injectJavaScriptBody = <<<'KOTLIN'
view.evaluateJavascript(requestCaptureJavaScript()) { result ->
    Log.d(TAG, "JavaScript injection result: $result")
}
KOTLIN;

        if (str_contains($text, 'private fun installRequestInterception()')) {
            [$text, $updated] = $this->setKotlinFunctionBody($text, 'installRequestInterception', $installRequestInterceptionBody);
            $changed = $changed || $updated;
        } else {
            $installRequestInterceptionDefinition = $this->buildKotlinFunctionDefinition('installRequestInterception', $installRequestInterceptionBody, 'private');
            $changed = $this->insertBeforeOrError(
                $text,
                '    private fun injectJavaScript(view: WebView) {',
                rtrim($installRequestInterceptionDefinition, "
"),
                'installRequestInterception definition',
            ) || $changed;
        }

        if (str_contains($text, 'private fun requestCaptureJavaScript(): String')) {
            [$text, $updated] = $this->setKotlinFunctionBody($text, 'requestCaptureJavaScript', $requestCaptureJavaScriptBody);
            $changed = $changed || $updated;
        } else {
            $requestCaptureJavaScriptDefinition = $this->buildKotlinFunctionDefinition('requestCaptureJavaScript', $requestCaptureJavaScriptBody, 'private', ': String');
            $changed = $this->insertBeforeOrError(
                $text,
                '    private fun injectJavaScript(view: WebView) {',
                rtrim($requestCaptureJavaScriptDefinition, "
"),
                'requestCaptureJavaScript definition',
            ) || $changed;
        }

        [$text, $updated] = $this->setKotlinFunctionBody($text, 'injectJavaScript', $injectJavaScriptBody);
        $changed = $changed || $updated;

        $this->writePatchResult($path, $text, $changed, 'native-request-capture');
    }

    private function buildKotlinFunctionDefinition(
        string $name,
        string $body,
        string $visibility = 'private',
        string $returnType = ''
    ): string {
        $lines = explode("
", $body);
        $indented = [];
        foreach ($lines as $line) {
            $indented[] = $line === '' ? '' : '        '.$line;
        }

        return "    {$visibility} fun {$name}(){$returnType} {
".implode("
", $indented)."
    }
";
    }

    private function buildComposableDefinition(string $name, string $body): string
    {
        $lines = explode("\n", $body);
        $indented = [];
        foreach ($lines as $line) {
            $indented[] = $line === '' ? '' : '        '.$line;
        }

        return "    @Composable\n    private fun {$name}() {\n".implode("\n", $indented)."\n    }\n";
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function setKotlinFunctionBody(string $text, string $funcName, string $newBody): array
    {
        [$indent, $start, $end] = $this->locateKotlinFunction($text, $funcName);
        $bodyIndent = $indent.'    ';
        $lines = explode("\n", $newBody);
        $indented = [];
        foreach ($lines as $line) {
            $indented[] = $line === '' ? '' : $bodyIndent.$line;
        }

        $replacement = substr($text, 0, $start + 1)."\n".implode("\n", $indented)."\n".$indent.'}'.substr($text, $end + 1);

        return [$replacement, $replacement !== $text];
    }

    /**
     * @return array{0: string, 1: int, 2: int}
     */
    private function locateKotlinFunction(string $text, string $funcName): array
    {
        $pattern = '/^([ \t]*)(?:(?:private|public|protected|override)\s+)*fun\s+'.preg_quote($funcName, '/').'\s*\(/m';
        if (! preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            throw new RuntimeException("function '{$funcName}' not found");
        }

        $indent = $matches[1][0];
        $matchPos = $matches[0][1];
        $matchLen = strlen($matches[0][0]);
        $start = strpos($text, '{', $matchPos + $matchLen);
        if ($start === false) {
            throw new RuntimeException("function '{$funcName}' has no opening body brace");
        }

        $depth = 1;
        $i = $start + 1;
        $len = strlen($text);
        $inString = false;
        $inTriple = false;
        $escape = false;
        $end = null;

        while ($i < $len) {
            if ($inTriple) {
                if (substr($text, $i, 3) === '"""') {
                    $inTriple = false;
                    $i += 3;
                    continue;
                }
            } elseif ($inString) {
                if ($escape) {
                    $escape = false;
                } elseif ($text[$i] === '\\') {
                    $escape = true;
                } elseif ($text[$i] === '"') {
                    $inString = false;
                }
            } else {
                if (substr($text, $i, 3) === '"""') {
                    $inTriple = true;
                    $i += 3;
                    continue;
                }
                if ($text[$i] === '"') {
                    $inString = true;
                } elseif ($text[$i] === '{') {
                    $depth++;
                } elseif ($text[$i] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $end = $i;
                        break;
                    }
                }
            }
            $i++;
        }

        if ($end === null) {
            throw new RuntimeException("function '{$funcName}' has no closing body brace");
        }

        return [$indent, $start, $end];
    }

    private function insertImport(string &$text, string $importLine, string $after, string $label): bool
    {
        if (str_contains($text, $importLine)) {
            return false;
        }

        if (! str_contains($text, $after)) {
            throw new RuntimeException("import anchor not found for {$label}");
        }

        $text = $this->replaceFirst($text, $after, $after."\n".$importLine);

        return true;
    }

    private function removeLine(string &$text, string $line): bool
    {
        if (! str_contains($text, $line)) {
            return false;
        }

        $text = $this->replaceFirst($text, $line, '');

        return true;
    }

    private function replaceOnceOrError(
        string &$text,
        string $old,
        string $new,
        string $label,
        ?string $alreadyContains = null,
    ): bool {
        if (str_contains($text, $old)) {
            $text = $this->replaceFirst($text, $old, $new);

            return true;
        }

        if ($alreadyContains !== null && str_contains($text, $alreadyContains)) {
            return false;
        }

        if (str_contains($text, $new)) {
            return false;
        }

        throw new RuntimeException("pattern not found for {$label}");
    }

    private function replaceRegexOnceOrError(
        string &$text,
        string $pattern,
        string $replacement,
        string $label,
        ?string $alreadyContains = null,
    ): bool {
        $count = 0;
        $updated = preg_replace('/'.$pattern.'/ms', $replacement, $text, 1, $count);
        if ($updated !== null && $count > 0) {
            $text = $updated;

            return true;
        }

        if ($alreadyContains !== null && str_contains($text, $alreadyContains)) {
            return false;
        }

        if (str_contains($text, $replacement)) {
            return false;
        }

        throw new RuntimeException("regex pattern not found for {$label}");
    }


    private function insertAfterOrError(string &$text, string $anchor, string $insert, string $label): bool
    {
        if (str_contains($text, $insert)) {
            return false;
        }

        if (! str_contains($text, $anchor)) {
            throw new RuntimeException("anchor not found for {$label}");
        }

        $text = $this->replaceFirst($text, $anchor, $anchor."\n\n".$insert);

        return true;
    }

    private function insertAfterShouldInterceptBlock(string &$text, string $insert, string $label): bool
    {
        if (str_contains($text, $insert)) {
            return false;
        }

        $needle = 'override fun shouldInterceptRequest';
        $start = strpos($text, $needle);
        if ($start === false) {
            throw new RuntimeException("anchor not found for {$label}");
        }

        $block = substr($text, $start);
        $urlLine = 'val url = request.url.toString()';
        $methodLine = 'val method = request.method';

        $urlIndex = strpos($block, $urlLine);
        if ($urlIndex === false) {
            throw new RuntimeException("anchor not found for {$label}");
        }

        $methodIndex = strpos($block, $methodLine, $urlIndex);
        if ($methodIndex === false) {
            throw new RuntimeException("anchor not found for {$label}");
        }

        $lineStart = strrpos(substr($block, 0, $methodIndex), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $lineEnd = strpos($block, "\n", $methodIndex);
        if ($lineEnd === false) {
            $lineEnd = $methodIndex + strlen($methodLine);
        }

        $indent = substr($block, $lineStart, $methodIndex - $lineStart);
        $lines = explode("\n", $insert);
        $indented = [];
        foreach ($lines as $line) {
            $indented[] = $line === '' ? '' : $indent.$line;
        }
        $insertBlock = "\n\n".implode("\n", $indented);

        $block = substr($block, 0, $lineEnd).$insertBlock.substr($block, $lineEnd);
        $text = substr($text, 0, $start).$block;

        return true;
    }

    private function insertBeforeOrError(string &$text, string $anchor, string $insert, string $label): bool
    {
        if (str_contains($text, $insert)) {
            return false;
        }

        if (! str_contains($text, $anchor)) {
            throw new RuntimeException("pattern not found for {$label}");
        }

        $text = $this->replaceFirst($text, $anchor, $insert."\n".$anchor);

        return true;
    }

    private function replaceFirst(string $text, string $search, string $replace): string
    {
        $pos = strpos($text, $search);
        if ($pos === false) {
            return $text;
        }

        return substr_replace($text, $replace, $pos, strlen($search));
    }

    private function writePatchResult(string $path, string $text, bool $changed, string $prefix): void
    {
        if ($changed) {
            file_put_contents($path, $text);
            $this->info("[{$prefix}] patched: {$path}");
        } else {
            $this->info("[{$prefix}] already ok: {$path}");
        }
    }
}
