<?php

declare(strict_types=1);

namespace Goodm4ven\NativePatches\Commands\Concerns;

use RuntimeException;

trait PatchesAndroidWebViewManager
{
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
            "import com.nativephp.mobile.bridge.LaravelEnvironment\n",
            "import java.io.ByteArrayInputStream\n",
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
                "    private var customViewCallback: WebChromeClient.CustomViewCallback? = null\n    private var requestInterceptionInstalled = false\n",
                $text,
                1,
            );
            $changed = true;
        } elseif (! str_contains($text, "    private var requestInterceptionInstalled = false\n")) {
            throw new RuntimeException('[native-request-capture] error: pattern not found for request interception field');
        }

        $changed = $this->replaceOnceOrError(
            $text,
            "        setupWebViewClient()\n        setupJavaScriptInterfaces()\n        WebViewManager.shared = this // 👈 make this instance globally accessible\n",
            "        setupWebViewClient()\n        setupJavaScriptInterfaces()\n        installRequestInterception()\n        WebViewManager.shared = this // 👈 make this instance globally accessible\n",
            'WebViewManager setup request interception',
            '        installRequestInterception()',
        ) || $changed;

        $changed = $this->replaceOnceOrError(
            $text,
            "                // Inject JavaScript to capture form submissions and AJAX requests\n                injectJavaScript(view)\n",
            "                // Fall back to page-finished injection if document-start scripts are unavailable\n                if (!WebViewFeature.isFeatureSupported(WebViewFeature.DOCUMENT_START_SCRIPT)) {\n                    injectJavaScript(view)\n                }\n",
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
                rtrim($installRequestInterceptionDefinition, "\n"),
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
                rtrim($requestCaptureJavaScriptDefinition, "\n"),
                'requestCaptureJavaScript definition',
            ) || $changed;
        }

        [$text, $updated] = $this->setKotlinFunctionBody($text, 'injectJavaScript', $injectJavaScriptBody);
        $changed = $changed || $updated;

        $this->writePatchResult($path, $text, $changed, 'native-request-capture');
    }
}
