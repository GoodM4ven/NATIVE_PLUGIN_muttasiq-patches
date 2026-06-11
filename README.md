<div align="center">بسم الله الرحمن الرحيم</div>
<div align="left">

# NativePHP Muttasiq Patches

An internal package for **[Muttasiq](https://github.com/GoodM4ven/NATIVE_TALL_muttasiq-dot-com)** that applies the NativePHP build patches required on top of `nativephp/mobile` during Android and iOS builds.

**This package is not meant to be a broad, general-purpose plugin.** It is a small compatibility layer for Muttasiq that replaces manual patch scripts with an official NativePHP plugin hook.

### Compatibility

- NativePHP Mobile `3.2.x` (verified against `3.2.2`).
- Patch anchors are maintained against the current `resources/androidstudio` and `resources/xcode` sources shipped by NativePHP Mobile 3.2.

### Objectives

- Applies the required `EDGE` patches in `nativephp/mobile` so empty navigation components are not rendered and nested native component trees are preserved correctly.
- Patches `MainActivity.kt` to improve system bars, `safe-area` injection, native back handling, and WebView state behavior.
- Launches Android emulators with a Linux-safe software-rendered GPU default (`-gpu swiftshader_indirect`) unless overridden through `NATIVEPHP_ANDROID_EMULATOR_ARGS`, avoiding the Vulkan/ZINK startup failure that can block `native:run android`.
- Adds Android splash-timing guard so warm launches keep splash visible for a short minimum duration (~1.6s floor), and harmonizes splash backdrop color with dark/light system theme to reduce startup flash.
- Adds Android Quran startup lifecycle tracing from `MainActivity.kt` (`onResume` / `onPause`) to both `logcat` and in-WebView custom events (`quran-native-lifecycle`) for debugging reader boot visibility/calibration issues.
- Adds Android bridge method `getAppFirstInstallTime()` so WebView-side state can detect reinstall fingerprints and reset stale Quran reader local storage when Android restores old WebView data.
- Adds Android bridge method `restartApplication()` so WebView flows can request a full native app restart (finish + process exit + relaunch intent) after first-run Quran data bootstrap.
- Adds Android bridge method `setScreenAwake(boolean)` so WebView readers can toggle `FLAG_KEEP_SCREEN_ON` only while immersive reading is active.
- Adds iOS WebKit bridge handlers `screenAwake`, `quranVolumeNavigation`, `restartApplication`, `exitApplication`, and `copyText` so WebView readers can toggle `UIApplication.shared.isIdleTimerDisabled`, opt into volume-button navigation, request a persistent-runtime reboot + WebView reload after Quran bootstrap completes, write directly to `UIPasteboard`, and let web-level back gestures exit the app on the main menu.
- Keeps the iOS Quran volume bridge anchored with a hidden `MPVolumeView` so output-volume KVO stays responsive when hardware volume keys are repurposed for reader navigation.
- Makes the iOS restart bridge always post a WebView reload notification after the runtime reboot attempt so the restart flow does not depend on a single success path.
- Makes the iOS injected helper and native web view treat Return / Escape / Backspace and the left-edge swipe as native back actions when no editable field is focused, and honors the app-level `"exit"` fallback on the iOS main menu instead of swallowing it.
- Patches Android `AndroidManifest.xml` + backup rule XMLs to disable cloud/device-transfer backup for app storage domains, preventing restored stale WebView/localStorage reader state after uninstall/reinstall.
- Patches `WebViewManager.kt` to install early request capture for `Livewire` and `Filament`, while preserving NativePHP 3.2 request-id forwarding (`X-NativePHP-Req-Id`) used by Android POST body replay.
- Keeps `WebViewManager.kt` request inspector hooks and noisy per-request logging debug-aware, reducing release-build interception/log overhead without removing request capture compatibility.
- Patches `PHPBridge.kt` to validate that persistent runtime boot is actually usable before enabling persistent mode, serializes `ensureRuntimeInitialized()` across concurrent caller threads, and auto-fallbacks to classic request handling if runtime boot state is lost.
- Patches `PHPWebViewClient.kt` so Muttasiq's Quran page fonts are streamed directly from the bundled raw-data files, and binary asset misses no longer fall back through the unsafe JNI string bridge.
- Includes route-aware Android Quran font interception for `qpc-v2-fonts`, `quran-surah-header-font`, and supported `quran-basmallah-font/*` requests so those font responses never flow through the PHP JNI string bridge.
- Patches `LaravelEnvironment.kt` bundle extraction to stream ZIP entries directly to disk (instead of buffering large files in memory), preventing first-launch `OutOfMemoryError` during Laravel bundle extraction.
- Adds Android startup phase timing logs (bundle unzip, native bootstrap, environment initialize, persistent runtime boot, onReady dispatch, splash dismiss) so cold-start bottlenecks can be profiled directly from `logcat`.
- Rewrites Android's `laravel_bundle.zip` after NativePHP builds it so the dormant Quran exegesis payload, generated local Quran snapshot artifacts (`database/native-quran-reader.*`), build debug sidecars (`public/build/assets/*.map`, `*.LICENSE.txt`), and stale Vite files not referenced by `public/build/manifest.json` are removed before the APK is packaged, avoiding Composer autoload regressions from vendor-package pruning while shrinking first-launch extraction.
- Skips extracting the dormant Quran exegesis database bundle on Android first launch as a second line of defense when older or unpruned bundles are still present.
- Keeps Android startup database bootstrap minimal (empty sqlite + `storage:link` when needed + `app:native-bootstrap`) so Quran payload can be delivered later by the app-level download flow instead of inflating first-launch extraction.
- Forces Android native runtime queue execution to `sync` so app-level bootstrap/download jobs do not depend on a separate queue worker process.
- Propagates optional build-time `NATIVE_*_ENDPOINT` overrides into Android runtime environment variables so local-source API broadcasts can be consumed by app HTTP jobs and startup sync, and auto-rewrites loopback overrides (`127.0.0.1` / `localhost`) to detected LAN IPv4 unless explicitly pinned.
- Emits a concise Android runtime environment summary in `logcat` for queue + endpoint variables after NativePHP environment setup, so local broadcast endpoint issues are visible without digging through bundled files.
- Patches `PHPQueueWorker.kt` to run `queue:work` in verbose mode and log queue command output, making native snapshot/import failures visible during Android debugging.
- Keeps Android `LaravelEnvironment.kt` summary logging idempotent across repeated patch runs.
- Patches iOS `ContentView.swift` to keep Muttasiq's edge-swipe back handling aligned with the app's in-web navigation behavior, expose an `AndroidBridge` shim for screen-awake / Quran volume / restart / exit / clipboard flows, aggressively suppress non-editable iOS WebKit text selection and callouts, and warn if upstream system UI layout expectations change.
- Patches iOS `NativePHPApp.swift` and `AppUpdateManager.swift` so native startup and app updates stay on sqlite and use the app-specific bootstrap command instead of raw `migrate --force`.
- Patches `MainActivity.kt` with a small JavaScript bridge so Muttasiq can opt into Quran page navigation via the Android hardware volume buttons.
- Keeps patch anchors tolerant of upstream engine refactors where possible, including Compose modifier-chain changes in `MainActivity.kt`.
- Runs as a NativePHP `pre_compile` hook, so separate shell patch scripts are no longer needed during builds.

### Patches

- `native-edge`: patches `TopBar`, `BottomNav`, and `Edge` internals.
- `native-system-ui`: patches `MainActivity.kt` for system bars, `safe-area`, first-launch reload behavior, and disabled WebView state saving.
- `native-system-ui`: includes startup timing traces for environment boot lifecycle phases and splash dismiss timing.
- `native-system-ui`: includes Quran lifecycle startup tracing dispatch (`quran-native-lifecycle`) from Android activity lifecycle hooks for runtime diagnostics.
- `native-system-ui`: includes Android bridge exposure of `getAppFirstInstallTime()` for native reinstall fingerprinting on the WebView side.
- `native-system-ui`: includes Android bridge exposure of `restartApplication()` for app-driven full restart flows after native Quran bootstrap completion.
- `native-system-ui`: includes Android bridge exposure of `setScreenAwake(boolean)` to control `FLAG_KEEP_SCREEN_ON` from WebView reader lifecycles.
- `native-ios-back`: includes iOS `screenAwake`, `quranVolumeNavigation`, `restartApplication`, `exitApplication`, and `copyText` WebKit message handlers plus injected JS bridge methods (`window.AndroidBridge.setScreenAwake`, `setQuranVolumeNavigationEnabled`, `restartApplication`, `exitApplication`, `copyText`) to control reader lifecycles and clipboard writes from the WebView.
- `native-back-handler`: upgrades native back button delegation so it first closes any open Filament modal in the WebView, then falls back to app navigation logic.
- `native-back-handler`: includes close-button fallback dispatch for Filament modals to ensure hardware back consumes open modals before view navigation.
- `native-back-handler`: includes explicit root-hash (`#main-menu` / `#`) exit fallback so Android system back quits the app when web navigation is already at the main menu.
- `native-google-reviews`: applies the app-specific Google review handling adjustments inside the activity.
- `native-request-capture`: installs reliable early interception for `Livewire` and `Filament` requests.
- `native-request-capture`: debug-gates request inspector work and verbose per-request logging for lower release runtime overhead.
- `native-persistent-runtime-guard`: verifies persistent runtime readiness after boot, and degrades to classic handling if Android ever returns `Runtime not booted` during persistent dispatch.
- `native-android-assets`: resolves Quran page fonts directly from the bundled raw-data tree and blocks binary-asset PHP fallback that would otherwise crash Android on `NewStringUTF`.
- `native-android-assets`: also resolves the route-backed Quran header and supported basmallah font endpoints from the bundled raw-data tree to avoid JNI crashes on binary font responses.
- `native-bundle-extract`: prunes the dormant Quran exegesis payload, generated local Quran snapshot artifacts (`database/native-quran-reader.*`), build debug sidecars (`public/build/assets/*.map`, `*.LICENSE.txt`), stale `public/build/assets/*` entries not referenced by `public/build/manifest.json`, dev-only Composer package directories from `composer.lock` (`packages-dev`), Arabicable raw-data source artifacts (`vendor/goodm4ven/arabicable/resources/raw-data/**/source-*`), and additional non-runtime bundle paths/files (vendor docs/tests/fixtures/`.github`, vendor source maps, and root build/test metadata like `phpstan.neon`, `phpunit.xml`, `pest.php`) from Android's generated `laravel_bundle.zip`; also stores already-compressed binary asset types (`woff2`, images, sqlite/db, gzip/zip, etc.) without re-deflating while rewriting the archive to reduce cold-start unzip CPU overhead. It patches `LaravelEnvironment.kt` unzip behavior to use streaming extraction with ZIP slip protection (including normalized path guards and a larger extraction buffer for lower per-entry overhead), keeps a runtime exegesis skip fallback, forces native queue execution to `sync`, applies optional `NATIVE_*_ENDPOINT` runtime overrides (with loopback-to-LAN normalization unless `NATIVE_ANDROID_KEEP_LOOPBACK_ENDPOINTS=1`), and swaps raw `migrate --force` for `app:native-bootstrap --no-interaction`.
- `native-bundle-extract`: includes unzip and bootstrap timing summaries in `logcat` to isolate cold-start delays by phase.
- `native-queue-worker-verbosity`: patches `PHPQueueWorker.kt` so Android queue ticks run with verbose Artisan output and include queue output logs in `logcat`.
- `native-no-backup`: patches Android manifest backup flags and backup rule XMLs to disable backup/restore for file, database, shared-pref, external, and root app domains.
- `native-ios-system-ui`: verifies the upstream iOS layout structure still exposes the top and bottom native chrome that Muttasiq expects.
- `native-ios-back`: patches `ContentView.swift` so the native left-edge gesture delegates to the app's web back action before falling back to WebView history.
- `native-ios-db-bootstrap`: patches `NativePHPApp.swift` so iOS startup and embedded artisan execution stay on sqlite, and classic/fallback startup paths still run migrations before serving requests.
- `native-ios-app-updates`: patches `AppUpdateManager.swift` so app updates also use the app-specific bootstrap command.
- `native-quran-volume-buttons`: patches `MainActivity.kt` so Muttasiq can enable Android hardware volume keys for Quran page navigation only while the reader is active.


## iOS SQLite startup fix

This package includes an iOS startup fix for a failure mode where the app launches but immediately fails with:

- `SQLSTATE[HY000]: General error: 1 no such table: settings`

The issue happens when native startup or embedded `artisan` execution uses a non-sqlite default connection inside iOS runtime, so migrations never apply to the simulator/device sqlite database, or only run on one startup path.

The `native-ios-db-bootstrap` patch addresses this by:

- forcing sqlite context for iOS native runtime environment setup
- forcing sqlite context for embedded artisan execution in `NativePHPApp.swift`
- ensuring the classic and persistent-fallback startup paths still run migrations before the first request


## Installation

```bash
composer require goodm4ven/nativephp-muttasiq-patches
php artisan vendor:publish --tag=nativephp-plugins-provider --no-interaction
php artisan native:plugin:register goodm4ven/nativephp-muttasiq-patches --no-interaction
```


## Usage

This package does not expose a `Facade`, `bridge functions`, or native events for app-level consumption. It is a build-time plugin only.

Once registered, NativePHP will invoke the following dispatcher automatically during builds:

```bash
php artisan nativephp:muttasiq:patches
```

That dispatcher is wired through the `pre_compile` hook declared in `nativephp.json`, and forwards to the platform-specific commands:

```bash
php artisan nativephp:muttasiq:patches-android
php artisan nativephp:muttasiq:patches-ios
```


## Development

If you are developing this package locally alongside the main app, wire it into the project as a path repository or local Composer dependency, then rebuild the native project you need:

```bash
php artisan native:install android --force --no-interaction
php artisan native:run android
```

```bash
php artisan native:install ios --force --no-interaction
php artisan native:run ios
```

The app-side native prepare wrappers refresh `nativephp/<platform>` whenever the plugin patch sources change. If you bypass those wrappers, reinstall manually before retesting so the pruned Android bundle is regenerated.

</div>

> [!NOTE]
> **Internal package:** this package was written primarily for Muttasiq. It may still be reusable elsewhere, but its assumptions currently follow this app's engine behavior and integration constraints.

<div align="left">


## License

This package is licensed under the GNU Affero General Public License v3.0 (`AGPL-3.0-only`). See [`LICENSE`](./LICENSE).

</div>
<div align="center">والله المستعان.</div>
