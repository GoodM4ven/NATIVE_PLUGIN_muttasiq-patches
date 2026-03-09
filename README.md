<div align="center">بسم الله الرحمن الرحيم</div>
<div align="left">

# NativePHP Muttasiq Patches

An internal package for **[Muttasiq](https://github.com/GoodM4ven/NATIVE_TALL_muttasiq-dot-com)** that applies the Android build patches required on top of `nativephp/mobile` during NativePHP builds.

**This package is not meant to be a broad, general-purpose plugin.** It is a small compatibility layer for Muttasiq that replaces manual patch scripts with an official NativePHP plugin hook.

### Objectives

- Applies the required `EDGE` patches in `nativephp/mobile` so empty navigation components are not rendered and nested native component trees are preserved correctly.
- Patches `MainActivity.kt` to improve system bars, `safe-area` injection, native back handling, and WebView state behavior.
- Patches `WebViewManager.kt` to install early request capture for `Livewire` and `Filament`, avoiding lost request bodies caused by late JavaScript injection.
- Runs as a NativePHP `pre_compile` hook, so separate shell patch scripts are no longer needed during builds.

### Patches

- `native-edge`: patches `TopBar`, `BottomNav`, and `Edge` internals.
- `native-system-ui`: patches `MainActivity.kt` for system bars, `safe-area`, first-launch reload behavior, and disabled WebView state saving.
- `native-back-handler`: upgrades native back button delegation so it can cooperate with the app's navigation logic.
- `native-google-reviews`: applies the app-specific Google review handling adjustments inside the activity.
- `native-request-capture`: installs reliable early interception for `Livewire` and `Filament` requests.


## Installation

```bash
composer require goodm4ven/nativephp-muttasiq-patches
php artisan vendor:publish --tag=nativephp-plugins-provider --no-interaction
php artisan native:plugin:register goodm4ven/nativephp-muttasiq-patches --no-interaction
```


## Usage

This package does not expose a `Facade`, `bridge functions`, or native events for app-level consumption. It is a build-time plugin only.

Once registered, NativePHP will invoke the following command automatically during builds:

```bash
php artisan nativephp:muttasiq:patches
```

That command is wired through the `pre_compile` hook declared in `nativephp.json`.


## Development

If you are developing this package locally alongside the main app, wire it into the project as a path repository or local Composer dependency, then rebuild the Android project:

```bash
php artisan native:install android --force --no-interaction
php artisan native:run android
```

</div>

> [!NOTE]
> **Internal package:** this package was written primarily for Muttasiq. It may still be reusable elsewhere, but its assumptions currently follow this app's engine behavior and integration constraints.

<div align="left">


## License

This package is licensed under the GNU Affero General Public License v3.0 (`AGPL-3.0-only`). See [`LICENSE`](./LICENSE).

</div>
<div align="center">والله المستعان.</div>
