<div align="center">بسم الله الرحمن الرحيم</div>
<div align="left">

# NativePHP Muttasiq Patches

An internal package for **[Muttasiq](https://github.com/GoodM4ven/NATIVE_TALL_muttasiq-dot-com)** that applies the NativePHP build patches required on top of `nativephp/mobile` during Android and iOS builds.

**This package is not meant to be a broad, general-purpose plugin.** It is a small compatibility layer for Muttasiq that replaces manual patch scripts with an official NativePHP plugin hook.

### Objectives

- Applies the required `EDGE` patches in `nativephp/mobile` so empty navigation components are not rendered and nested native component trees are preserved correctly.
- Patches `MainActivity.kt` to improve system bars, `safe-area` injection, native back handling, and WebView state behavior.
- Patches `WebViewManager.kt` to install early request capture for `Livewire` and `Filament`, avoiding lost request bodies caused by late JavaScript injection.
- Patches `LaravelEnvironment.kt` bundle extraction to stream ZIP entries directly to disk (instead of buffering large files in memory), preventing first-launch `OutOfMemoryError` during Laravel bundle extraction.
- Patches iOS `ContentView.swift` to keep Muttasiq's edge-swipe back handling aligned with the app's in-web navigation behavior, while warning if upstream system UI layout expectations change.
- Patches iOS `NativePHPApp.swift` to enforce sqlite for embedded `artisan` execution and run migrations during deferred startup, preventing first-launch schema misses such as `no such table: settings`.
- Runs as a NativePHP `pre_compile` hook, so separate shell patch scripts are no longer needed during builds.

### Patches

- `native-edge`: patches `TopBar`, `BottomNav`, and `Edge` internals.
- `native-system-ui`: patches `MainActivity.kt` for system bars, `safe-area`, first-launch reload behavior, and disabled WebView state saving.
- `native-back-handler`: upgrades native back button delegation so it can cooperate with the app's navigation logic.
- `native-google-reviews`: applies the app-specific Google review handling adjustments inside the activity.
- `native-request-capture`: installs reliable early interception for `Livewire` and `Filament` requests.
- `native-bundle-extract`: patches `LaravelEnvironment.kt` unzip behavior to use streaming extraction with ZIP slip protection.
- `native-ios-system-ui`: verifies the upstream iOS layout structure still exposes the top and bottom native chrome that Muttasiq expects.
- `native-ios-back`: patches `ContentView.swift` so the native left-edge gesture delegates to the app's web back action before falling back to WebView history.
- `native-ios-db-bootstrap`: patches `NativePHPApp.swift` so iOS embedded artisan runs with `NATIVEPHP_RUNNING=true` and `DB_CONNECTION=sqlite`, and performs a deferred `migrate --force` before first request handling.


## iOS SQLite startup fix

This package includes an iOS startup fix for a failure mode where the app launches but immediately fails with:

- `SQLSTATE[HY000]: General error: 1 no such table: settings`

The issue happens when embedded `artisan migrate` executes with a non-sqlite default connection (for example `mysql`) inside iOS runtime, so migrations never apply to the simulator/device sqlite database.

The `native-ios-db-bootstrap` patch addresses this by:

- forcing sqlite context for embedded artisan execution in `NativePHPApp.swift`
- running deferred migrations during app startup before the first WebView request


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

</div>

> [!NOTE]
> **Internal package:** this package was written primarily for Muttasiq. It may still be reusable elsewhere, but its assumptions currently follow this app's engine behavior and integration constraints.

<div align="left">


## License

This package is licensed under the GNU Affero General Public License v3.0 (`AGPL-3.0-only`). See [`LICENSE`](./LICENSE).

</div>
<div align="center">والله المستعان.</div>
