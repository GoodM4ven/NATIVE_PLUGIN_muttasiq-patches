<?php

declare(strict_types=1);

namespace Goodm4ven\NativePatches\Commands\Concerns;

use RuntimeException;

trait PatchesIosContentView
{
    private function verifyIosSystemUi(string $path): void
    {
        if (! file_exists($path)) {
            $this->info("[native-ios-system-ui] skip missing: {$path}");

            return;
        }

        $text = file_get_contents($path);
        if ($text === false) {
            throw new RuntimeException("[native-ios-system-ui] error: unable to read {$path}");
        }

        $hasTopSafeArea = str_contains($text, '.safeAreaInset(edge: .top');
        $hasBottomSafeArea = str_contains($text, '.safeAreaInset(edge: .bottom');
        $hasNativeTopBar = str_contains($text, 'NativeTopBar(');
        $hasNativeBottomNav = str_contains($text, 'NativeBottomNavigation(');

        if ($hasTopSafeArea && $hasBottomSafeArea && $hasNativeTopBar && $hasNativeBottomNav) {
            $this->info("[native-ios-system-ui] no patch required (upstream layout handling present): {$path}");

            return;
        }

        $this->warn("[native-ios-system-ui] upstream UI structure changed, re-check iOS system UI behavior: {$path}");
    }

    private function patchIosBackHandler(string $path): void
    {
        if (! file_exists($path)) {
            $this->info("[native-ios-back] skip missing: {$path}");

            return;
        }

        $text = file_get_contents($path);
        if ($text === false) {
            throw new RuntimeException("[native-ios-back] error: unable to read {$path}");
        }

        $changed = false;

        if (str_contains($text, 'import UIKitimport AVFoundation')) {
            $repairCount = 0;
            $text = str_replace(
                'import UIKitimport AVFoundation',
                "import UIKit\nimport AVFoundation",
                $text,
                $repairCount,
            );
            $changed = $repairCount > 0 || $changed;
        }

        $changed = $this->insertImport(
            $text,
            'import AVFoundation',
            'import WebKit',
            'iOS AVFoundation import',
        ) || $changed;

        $changed = $this->insertImport(
            $text,
            'import MediaPlayer',
            'import AVFoundation',
            'iOS MediaPlayer import',
        ) || $changed;

        if (str_contains($text, "import WebKit\nimport UIKit\nimport UIKit\n")) {
            $text = $this->replaceFirst($text, "import WebKit\nimport UIKit\nimport UIKit\n", "import WebKit\nimport UIKit\n");
            $changed = true;
        }

        $changed = $this->insertImport(
            $text,
            'import UIKit',
            "import WebKit\n",
            'iOS UIKit import',
        ) || $changed;

        $changed = $this->replaceOnceOrError(
            $text,
            '        addNativeHelper(webView: webView)',
            '        addNativeHelper(webView: webView, context: context)',
            'iOS native helper context propagation',
            'addNativeHelper(webView: webView, context: context)',
        ) || $changed;

        $changed = $this->replaceOnceOrError(
            $text,
            '    static let dataStore = WKWebsiteDataStore.nonPersistent()',
            '    static let dataStore = WKWebsiteDataStore.default()',
            'iOS persistent website data store',
            '    static let dataStore = WKWebsiteDataStore.default()',
        ) || $changed;

        $changed = $this->replaceOnceOrError(
            $text,
            '    class Coordinator: NSObject, WKNavigationDelegate {',
            '    class Coordinator: NSObject, WKNavigationDelegate, WKScriptMessageHandler, UIGestureRecognizerDelegate {',
            'iOS Coordinator gesture delegate conformance',
            'UIGestureRecognizerDelegate',
        ) || $changed;

        if (! str_contains($text, '        private var quranVolumeNavigationEnabled = false')) {
            $changed = $this->replaceOnceOrError(
                $text,
                "        var hasCompletedInitialLoad = false\n",
                <<<'SWIFT'
        var hasCompletedInitialLoad = false
        private var quranVolumeNavigationEnabled = false
        private var quranVolumeLastObservedValue: Float?
        private var quranVolumeObservation: NSKeyValueObservation?
        private weak var quranVolumeControlView: MPVolumeView?
        private var shouldLoadStartURLOnNextReload = false

SWIFT,
                'iOS Quran volume navigation coordinator state',
            ) || $changed;
        }

        $changed = $this->replaceOnceOrError(
            $text,
            "        contentController.addUserScript(script)\n    }\n",
            "        contentController.addUserScript(script)\n        contentController.add(context.coordinator, name: \"screenAwake\")\n        contentController.add(context.coordinator, name: \"quranVolumeNavigation\")\n        contentController.add(context.coordinator, name: \"restartApplication\")\n        contentController.add(context.coordinator, name: \"exitApplication\")\n        contentController.add(context.coordinator, name: \"copyText\")\n    }\n",
            'iOS message handler registration',
            'contentController.add(context.coordinator, name: "copyText")',
        ) || $changed;

        $changed = $this->replaceOnceOrError(
            $text,
            '    func addNativeHelper(webView: WKWebView) {',
            '    func addNativeHelper(webView: WKWebView, context: Context) {',
            'iOS native helper signature',
            'func addNativeHelper(webView: WKWebView, context: Context) {',
        ) || $changed;

        if (! str_contains($text, 'final class NativeBackAwareWebView: WKWebView {')) {
            $changed = $this->insertBeforeOrError(
                $text,
                'struct WebView: UIViewRepresentable {',
                <<<'SWIFT'
final class NativeBackAwareWebView: WKWebView {
    override var canBecomeFirstResponder: Bool {
        true
    }

    override var keyCommands: [UIKeyCommand]? {
        [
            UIKeyCommand(input: UIKeyCommand.inputEscape, modifierFlags: [], action: #selector(handleNativeBackKeyCommand)),
            UIKeyCommand(input: UIKeyCommand.inputDelete, modifierFlags: [], action: #selector(handleNativeBackKeyCommand)),
            UIKeyCommand(input: "\r", modifierFlags: [], action: #selector(handleNativeBackKeyCommand)),
            UIKeyCommand(input: "\n", modifierFlags: [], action: #selector(handleNativeBackKeyCommand)),
        ]
    }

    @objc private func handleNativeBackKeyCommand() {
        let js = "(function() { try { return !!(window.__nativeBackAction && window.__nativeBackAction()); } catch (e) { return false; } })();"

        evaluateJavaScript(js) { [weak self] value, _ in
            let handled = (value as? Bool) == true
            let shouldExit = (value as? String) == "exit"

            if handled {
                return
            }

            if shouldExit {
                self?.requestAppExit()
                return
            }

            if self?.canGoBack == true {
                self?.goBack()
            }
        }
    }

    private func requestAppExit() {
        DispatchQueue.main.async {
            UIApplication.shared.perform(#selector(NSXPCConnection.suspend))
            exit(0)
        }
    }
}

SWIFT,
                'iOS native back-aware web view',
            ) || $changed;
        }

        $changed = $this->replaceOnceOrError(
            $text,
            <<<'SWIFT'
        window.Native = Native;

        document.addEventListener("native-event", function (e) {
            e.detail.event = e.detail.event.replace(/^(\\\\)+/, '');

            if (window.Livewire) {
                window.Livewire.dispatch('native:' + e.detail.event, e.detail.payload);
            }
        });

        (function() {
            // Add platform identifier class
            document.body.classList.add('nativephp-ios');

            // Disable text selection
            document.body.style.userSelect = "none";
        })();
SWIFT,
            <<<'SWIFT'
        window.Native = Native;

        window.addEventListener("native-bridge-ready", function () {
        });

        document.addEventListener("keydown", function (event) {
            const key = String(event.key ?? '').toLowerCase();
            const isBackKey = key === 'escape' || key === 'backspace' || key === 'delete';
            const isReturnKey = key === 'enter' || key === 'return';

            if (!isBackKey && !isReturnKey) {
                return;
            }

            const activeElement = document.activeElement;
            const tagName = String(activeElement?.tagName ?? '').toLowerCase();
            const isEditable = Boolean(
                activeElement &&
                (activeElement.isContentEditable || tagName === 'input' || tagName === 'textarea' || tagName === 'select')
            );

            if (isEditable) {
                return;
            }

            try {
                if (typeof window.__nativeBackAction === 'function' && window.__nativeBackAction()) {
                    event.preventDefault();
                    event.stopPropagation();
                }
            } catch (_) {
                // No-op: bridge unavailable.
            }
        }, true);

        const isEditableElement = function(element) {
            if (!element) {
                return false;
            }

            const editableRoot = element.closest?.(
                'input, textarea, select, [contenteditable="true"], [contenteditable="plaintext-only"]'
            );

            if (!editableRoot) {
                return false;
            }

            return !editableRoot.hasAttribute('readonly') && !editableRoot.hasAttribute('disabled');
        };

        const shouldSuppressNativeSelection = function(target) {
            return !isEditableElement(target instanceof Element ? target : null);
        };

        document.addEventListener("selectstart", function (event) {
            if (!shouldSuppressNativeSelection(event.target)) {
                return;
            }

            event.preventDefault();
        }, true);

        document.addEventListener("contextmenu", function (event) {
            if (!shouldSuppressNativeSelection(event.target)) {
                return;
            }

            event.preventDefault();
        }, true);

        document.addEventListener("dragstart", function (event) {
            if (!shouldSuppressNativeSelection(event.target)) {
                return;
            }

            event.preventDefault();
        }, true);

        if (typeof window.AndroidBridge !== 'object' || window.AndroidBridge === null) {
            window.AndroidBridge = {};
        }

        window.AndroidBridge.setScreenAwake = function(enabled) {
            try {
                window.webkit.messageHandlers.screenAwake.postMessage({ enabled: Boolean(enabled) });
            } catch (_) {
                // No-op: bridge unavailable.
            }
        };

        window.AndroidBridge.setKeepScreenAwake = window.AndroidBridge.setScreenAwake;

        window.AndroidBridge.setQuranVolumeNavigationEnabled = function(enabled) {
            try {
                window.webkit.messageHandlers.quranVolumeNavigation.postMessage({ enabled: Boolean(enabled) });
            } catch (_) {
                // No-op: bridge unavailable.
            }
        };

        window.AndroidBridge.restartApplication = function() {
            try {
                window.webkit.messageHandlers.restartApplication.postMessage({});
            } catch (_) {
                // No-op: bridge unavailable.
            }
        };

        window.AndroidBridge.exitApplication = function() {
            try {
                window.webkit.messageHandlers.exitApplication.postMessage({});
            } catch (_) {
                // No-op: bridge unavailable.
            }
        };

        window.AndroidBridge.copyText = function(text) {
            try {
                window.webkit.messageHandlers.copyText.postMessage({ text: String(text ?? '') });
                return true;
            } catch (_) {
                return false;
            }
        };

        window.dispatchEvent(new Event("native-bridge-ready"));

        window.focus();
        if (document.body) {
            document.body.tabIndex = -1;
            document.body.focus();
        }

        document.addEventListener("native-event", function (e) {
            e.detail.event = e.detail.event.replace(/^(\\\\)+/, '');

            if (window.Livewire) {
                window.Livewire.dispatch('native:' + e.detail.event, e.detail.payload);
            }
        });

        (function() {
            // Add platform identifier class
            document.body.classList.add('nativephp-ios');

            const selectionSuppressionStyle = document.createElement('style');
            selectionSuppressionStyle.textContent = `
                body.nativephp-ios,
                body.nativephp-ios *:not(input):not(textarea):not(select):not([contenteditable="true"]):not([contenteditable="plaintext-only"]) {
                    -webkit-touch-callout: none !important;
                    -webkit-user-select: none !important;
                    user-select: none !important;
                    -webkit-user-drag: none !important;
                }
            `;
            document.head?.appendChild(selectionSuppressionStyle);
        })();
SWIFT,
            'iOS injected screen awake bridge shim',
            'window.AndroidBridge.copyText = function(text)',
        ) || $changed;

        $changed = $this->replaceOnceOrError(
            $text,
            '        let webView = WKWebView(frame: .zero, configuration: webConfiguration)',
            '        let webView = NativeBackAwareWebView(frame: .zero, configuration: webConfiguration)',
            'iOS native back-aware web view construction',
            'NativeBackAwareWebView(frame: .zero, configuration: webConfiguration)',
        ) || $changed;

        $changed = $this->replaceOnceOrError(
            $text,
            <<<'SWIFT'
            DispatchQueue.main.async {
                UIView.animate(withDuration: 0.2) {
                    webView.alpha = 1.0
                }
            }
SWIFT,
            <<<'SWIFT'
            DispatchQueue.main.async {
                UIView.animate(withDuration: 0.2) {
                    webView.alpha = 1.0
                }
                webView.becomeFirstResponder()
            }
SWIFT,
            'iOS web view first responder refresh',
            'webView.becomeFirstResponder()',
        ) || $changed;

        $changed = $this->replaceOnceOrError(
            $text,
            <<<'SWIFT'
        @objc func reloadWebView() {
            // Views are already cleared during persistent runtime reboot — just reload
            self.webView?.reload()
        }
SWIFT,
            <<<'SWIFT'
        @objc func reloadWebView() {
            if shouldLoadStartURLOnNextReload {
                shouldLoadStartURLOnNextReload = false

                let startPath = NativePHPApp.getStartURL()
                let startPage = URL(string: "php://127.0.0.1\(startPath)")

                if let startPage {
                    self.webView?.load(URLRequest(url: startPage))
                } else {
                    self.webView?.reload()
                }

                return
            }

            self.webView?.reload()
        }
SWIFT,
            'iOS reload webview start-url reset',
            'shouldLoadStartURLOnNextReload',
        ) || $changed;

        $backHandlerMethods = <<<'SWIFT'
        func userContentController(_ userContentController: WKUserContentController, didReceive message: WKScriptMessage) {
            switch message.name {
            case "screenAwake":
                let payload = message.body as? [String: Any]
                let enabled = payload?["enabled"] as? Bool ?? false

                DispatchQueue.main.async {
                    UIApplication.shared.isIdleTimerDisabled = enabled
                }
            case "quranVolumeNavigation":
                let payload = message.body as? [String: Any]
                let enabled = payload?["enabled"] as? Bool ?? false

                setQuranVolumeNavigationEnabled(enabled)
            case "restartApplication":
                restartApplication()
            case "exitApplication":
                requestAppExit()
            case "copyText":
                let payload = message.body as? [String: Any]
                let text = payload?["text"] as? String ?? ""

                DispatchQueue.main.async {
                    UIPasteboard.general.string = text
                }
            default:
                break
            }
        }

        private func setQuranVolumeNavigationEnabled(_ enabled: Bool) {
            DispatchQueue.main.async {
                self.quranVolumeNavigationEnabled = enabled

                self.quranVolumeObservation?.invalidate()
                self.quranVolumeObservation = nil

                if !enabled {
                    self.quranVolumeLastObservedValue = nil
                    self.removeQuranVolumeControlView()

                    do {
                        try AVAudioSession.sharedInstance().setActive(false, options: .notifyOthersOnDeactivation)
                    } catch {
                        print("Failed to deactivate iOS volume navigation audio session: \(error)")
                    }

                    return
                }

                let session = AVAudioSession.sharedInstance()
                do {
                    try session.setCategory(.ambient, mode: .default)
                    try session.setActive(true)
                } catch {
                    print("Failed to activate iOS volume navigation audio session: \(error)")
                }

                self.installQuranVolumeControlViewIfNeeded()
                self.quranVolumeLastObservedValue = session.outputVolume

                self.quranVolumeObservation = session.observe(
                    \.outputVolume,
                    options: [.new]
                ) { [weak self] session, change in
                    guard let self else {
                        return
                    }

                    guard self.quranVolumeNavigationEnabled else {
                        return
                    }

                    guard let currentValue = change.newValue else {
                        return
                    }

                    let previousValue = self.quranVolumeLastObservedValue ?? currentValue

                    if abs(currentValue - previousValue) < 0.001 {
                        return
                    }

                    self.quranVolumeLastObservedValue = currentValue

                    let direction = currentValue > previousValue ? "next" : "previous"
                    DispatchQueue.main.async {
                        self.dispatchQuranVolumeButton(direction: direction)
                    }
                }
            }
        }

        private func installQuranVolumeControlViewIfNeeded() {
            guard quranVolumeControlView == nil else {
                return
            }

            guard let webView else {
                return
            }

            let volumeView = MPVolumeView(frame: CGRect(x: -1000, y: -1000, width: 1, height: 1))
            volumeView.alpha = 0.01
            volumeView.isUserInteractionEnabled = false
            volumeView.showsRouteButton = false
            volumeView.showsVolumeSlider = true
            webView.addSubview(volumeView)
            quranVolumeControlView = volumeView
        }

        private func removeQuranVolumeControlView() {
            quranVolumeControlView?.removeFromSuperview()
            quranVolumeControlView = nil
        }

        private func dispatchQuranVolumeButton(direction: String) {
            let escapedDirection = direction
                .replacingOccurrences(of: "\\", with: "\\\\")
                .replacingOccurrences(of: "'", with: "\\'")

            let jsCode = """
            (function() {
                const payload = { direction: '\(escapedDirection)' };

                window.dispatchEvent(new CustomEvent('quran-native-volume-button', {
                    detail: payload,
                }));

                if (window.Native && typeof window.Native.dispatch === 'function') {
                    window.Native.dispatch('quran-volume-button', payload);
                }
            })();
            """

            webView?.evaluateJavaScript(jsCode, completionHandler: nil)
        }

        private func restartApplication() {
            DispatchQueue.global(qos: .userInitiated).async {
                self.shouldLoadStartURLOnNextReload = true
                let rebooted = PersistentPHPRuntime.shared.reboot()
                let startPath = NativePHPApp.getStartURL()
                let startPage = URL(string: "php://127.0.0.1\(startPath)")

                DispatchQueue.main.async {
                    let reloadWebView = {
                        self.webView?.stopLoading()

                        if let startPage {
                            self.webView?.load(URLRequest(url: startPage))
                        } else if rebooted {
                            self.webView?.reloadFromOrigin()
                        } else {
                            self.webView?.reload()
                        }

                        NotificationCenter.default.post(name: .reloadWebViewNotification, object: nil)
                    }

                    if rebooted {
                        DispatchQueue.main.asyncAfter(deadline: .now() + 0.15) {
                            reloadWebView()
                        }
                    } else {
                        reloadWebView()
                    }
                }
            }
        }

        @objc func handleBackEdgeGesture(_ gesture: UIScreenEdgePanGestureRecognizer) {
            guard gesture.state == .ended else {
                return
            }

            let js = "(function() { try { return !!(window.__nativeBackAction && window.__nativeBackAction()); } catch (e) { return false; } })();"

            webView?.evaluateJavaScript(js) { [weak self] value, _ in
                let handled = (value as? Bool) == true
                let shouldExit = (value as? String) == "exit"

                if handled {
                    return
                }

                if shouldExit {
                    self?.requestAppExit()
                    return
                }

                if self?.webView?.canGoBack == true {
                    self?.webView?.goBack()
                }
            }
        }

        func gestureRecognizerShouldBegin(_ gestureRecognizer: UIGestureRecognizer) -> Bool {
            return true
        }

        func gestureRecognizer(
            _ gestureRecognizer: UIGestureRecognizer,
            shouldRecognizeSimultaneouslyWith otherGestureRecognizer: UIGestureRecognizer
        ) -> Bool {
            return true
        }

        private func requestAppExit() {
            DispatchQueue.main.async {
                UIApplication.shared.perform(#selector(NSXPCConnection.suspend))
                exit(0)
            }
        }

SWIFT;

        $changed = $this->insertBeforeOrError(
            $text,
            '        @objc func reloadWebView()',
            $backHandlerMethods,
            'iOS back handler methods',
        ) || $changed;

        $updatedText = preg_replace(
            '/let handled:\s*Bool\s*=\s*\{[\s\S]*?\}\(\)/m',
            'let handled = (value as? Bool) == true',
            $text,
            1,
            $legacyHandledReplacements,
        );
        if ($updatedText !== null && $legacyHandledReplacements > 0) {
            $text = $updatedText;
            $changed = true;
        }

        $swipeSupportBody = <<<'SWIFT'
webView.navigationDelegate = context.coordinator
webView.allowsBackForwardNavigationGestures = false

let backGestureName = "NativePHPBackEdgeGesture"
let hasBackGesture = webView.gestureRecognizers?.contains(where: { $0.name == backGestureName }) == true

if !hasBackGesture {
    let edgePan = UIScreenEdgePanGestureRecognizer(
        target: context.coordinator,
        action: #selector(Coordinator.handleBackEdgeGesture(_:))
    )
    edgePan.name = backGestureName
    edgePan.edges = .left
    edgePan.delegate = context.coordinator
    webView.addGestureRecognizer(edgePan)
}
SWIFT;

        [$text, $updated] = $this->setSwiftFunctionBody($text, 'addSwipeGestureSupport', $swipeSupportBody);
        $changed = $changed || $updated;

        $this->writePatchResult($path, $text, $changed, 'native-ios-back');
    }
}
