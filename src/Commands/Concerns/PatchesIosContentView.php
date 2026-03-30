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
            '    static let dataStore = WKWebsiteDataStore.nonPersistent()',
            '    static let dataStore = WKWebsiteDataStore.default()',
            'iOS persistent website data store',
            '    static let dataStore = WKWebsiteDataStore.default()',
        ) || $changed;

        $changed = $this->replaceOnceOrError(
            $text,
            '    class Coordinator: NSObject, WKNavigationDelegate {',
            '    class Coordinator: NSObject, WKNavigationDelegate, UIGestureRecognizerDelegate {',
            'iOS Coordinator gesture delegate conformance',
            'UIGestureRecognizerDelegate',
        ) || $changed;

        $backHandlerMethods = <<<'SWIFT'
        @objc func handleBackEdgeGesture(_ gesture: UIScreenEdgePanGestureRecognizer) {
            guard gesture.state == .ended else {
                return
            }

            let js = "(function() { try { return !!(window.__nativeBackAction && window.__nativeBackAction()); } catch (e) { return false; } })();"

            webView?.evaluateJavaScript(js) { [weak self] value, _ in
                let handled = (value as? Bool) == true

                if handled {
                    return
                }

                if self?.webView?.canGoBack == true {
                    self?.webView?.goBack()
                }
            }
        }

        func gestureRecognizerShouldBegin(_ gestureRecognizer: UIGestureRecognizer) -> Bool {
            guard let pan = gestureRecognizer as? UIScreenEdgePanGestureRecognizer,
                  let view = pan.view else {
                return true
            }

            let velocity = pan.velocity(in: view)
            return abs(velocity.x) >= abs(velocity.y) && velocity.x > 0
        }

        func gestureRecognizer(
            _ gestureRecognizer: UIGestureRecognizer,
            shouldRecognizeSimultaneouslyWith otherGestureRecognizer: UIGestureRecognizer
        ) -> Bool {
            return true
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
