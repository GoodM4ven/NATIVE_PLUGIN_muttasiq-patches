<?php

declare(strict_types=1);

namespace Goodm4ven\NativePatches\Commands\Concerns;

use RuntimeException;

trait PatchesAndroidSecureStorage
{
    private function patchAndroidSecureStorage(string $pluginsRegistrationPath, string $functionsPath): void
    {
        $this->patchAndroidPluginRegistrations($pluginsRegistrationPath);

        $this->writeTextFile(
            $functionsPath,
            <<<'KOTLIN'
package com.nativephp.mobile.bridge.plugins.functions

import android.content.Context
import android.content.SharedPreferences
import android.util.Log
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import com.nativephp.mobile.bridge.BridgeError
import com.nativephp.mobile.bridge.BridgeFunction
import org.json.JSONObject

private const val SECURE_STORAGE_PREFS_NAME = "nativephp_secure_storage"

private object SecureStorageStore {
    @Volatile
    private var cachedPreferences: SharedPreferences? = null

    @Volatile
    private var cachedContext: Context? = null

    fun preferences(context: Context): SharedPreferences {
        val applicationContext = context.applicationContext
        val existingPreferences = cachedPreferences
        if (existingPreferences != null && cachedContext === applicationContext) {
            return existingPreferences
        }

        synchronized(this) {
            cachedPreferences?.let { cached ->
                if (cachedContext === applicationContext) {
                    return cached
                }
            }

            val masterKey = MasterKey.Builder(applicationContext)
                .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
                .build()

            val preferences = EncryptedSharedPreferences.create(
                applicationContext,
                SECURE_STORAGE_PREFS_NAME,
                masterKey,
                EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
                EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
            )

            cachedContext = applicationContext
            cachedPreferences = preferences

            return preferences
        }
    }
}

private fun Any?.asSecureStorageValue(): String? {
    if (this == null || this == JSONObject.NULL) {
        return null
    }

    return toString()
}

private fun normalizedSecureStorageKey(parameters: Map<String, Any>): String {
    val key = parameters["key"]?.asSecureStorageValue()?.trim().orEmpty()

    if (key.isEmpty()) {
        throw BridgeError.InvalidParameters("key is required")
    }

    return key
}

private fun secureStoragePreferences(context: Context): SharedPreferences {
    return SecureStorageStore.preferences(context)
}

private fun commitSecureStorageEdit(edit: SharedPreferences.Editor, operation: String): Boolean {
    if (edit.commit()) {
        return true
    }

    throw BridgeError.ExecutionFailed("Failed to $operation secure value")
}

/**
 * Functions related to secure native storage.
 * Namespace: "SecureStorage.*"
 */
object SecureStorageFunctions {
    class Set(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val key = normalizedSecureStorageKey(parameters)
            val value = parameters["value"].asSecureStorageValue()
            val preferences = secureStoragePreferences(context)

            Log.d("SecureStorage.Set", "Writing key: $key")

            return try {
                val edit = preferences.edit()

                if (value === null) {
                    edit.remove(key)
                } else {
                    edit.putString(key, value)
                }

                commitSecureStorageEdit(edit, "store")

                mapOf("success" to true)
            } catch (error: BridgeError) {
                throw error
            } catch (error: Exception) {
                throw BridgeError.ExecutionFailed("Failed to store secure value: ${error.message}")
            }
        }
    }

        class Get(private val context: Context) : BridgeFunction {
            override fun execute(parameters: Map<String, Any>): Map<String, Any> {
                val key = normalizedSecureStorageKey(parameters)
                val preferences = secureStoragePreferences(context)
                val value = preferences.getString(key, null)

            Log.d("SecureStorage.Get", "Reading key: $key")

            return mapOf<String, Any>("value" to (value ?: JSONObject.NULL))
        }
    }

    class Delete(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val key = normalizedSecureStorageKey(parameters)
            val preferences = secureStoragePreferences(context)

            Log.d("SecureStorage.Delete", "Deleting key: $key")

            return try {
                commitSecureStorageEdit(preferences.edit().remove(key), "delete")

                mapOf("success" to true)
            } catch (error: BridgeError) {
                throw error
            } catch (error: Exception) {
                throw BridgeError.ExecutionFailed("Failed to delete secure value: ${error.message}")
            }
        }
    }
}
KOTLIN,
            'native-secure-storage',
        );
    }

    private function patchAndroidPluginRegistrations(string $path): void
    {
        if (! file_exists($path)) {
            $this->writeTextFile(
                $path,
                <<<'KOTLIN'
package com.nativephp.mobile.bridge.plugins

import android.content.Context
import androidx.fragment.app.FragmentActivity
import com.nativephp.browser.BrowserFunctions
import com.nativephp.mobile.bridge.BridgeFunctionRegistry
import com.nativephp.mobile.bridge.plugins.functions.SecureStorageFunctions

// AUTO-GENERATED FILE - DO NOT EDIT
// This file is overwritten during the build process with plugin registrations

fun registerPluginBridgeFunctions(activity: FragmentActivity, context: Context) {
    val registry = BridgeFunctionRegistry.shared

    registry.register("Browser.Open", BrowserFunctions.Open(activity))
    registry.register("Browser.OpenInApp", BrowserFunctions.OpenInApp(activity))
    registry.register("Browser.OpenAuth", BrowserFunctions.OpenAuth(activity))
    registry.register("SecureStorage.Set", SecureStorageFunctions.Set(context.applicationContext))
    registry.register("SecureStorage.Get", SecureStorageFunctions.Get(context.applicationContext))
    registry.register("SecureStorage.Delete", SecureStorageFunctions.Delete(context.applicationContext))
}
KOTLIN,
                'native-secure-storage',
            );

            return;
        }

        $text = file_get_contents($path);

        if ($text === false) {
            throw new RuntimeException("[native-secure-storage] error: unable to read [{$path}]");
        }

        $changed = false;

        $changed = $this->insertImport(
            $text,
            'import com.nativephp.mobile.bridge.plugins.functions.SecureStorageFunctions',
            'import com.nativephp.mobile.bridge.BridgeFunctionRegistry',
            'native-secure-storage',
        ) || $changed;

        if (! str_contains($text, 'registry.register("Browser.OpenAuth", BrowserFunctions.OpenAuth(activity))')) {
            [, , $functionEnd] = $this->locateKotlinFunction($text, 'registerPluginBridgeFunctions');
            $insertion = "\n    registry.register(\"Browser.OpenAuth\", BrowserFunctions.OpenAuth(activity))";
            $text = substr($text, 0, $functionEnd).$insertion.substr($text, $functionEnd);
            $changed = true;
        }

        foreach ([
            'registry.register("SecureStorage.Set", SecureStorageFunctions.Set(context.applicationContext))',
            'registry.register("SecureStorage.Get", SecureStorageFunctions.Get(context.applicationContext))',
            'registry.register("SecureStorage.Delete", SecureStorageFunctions.Delete(context.applicationContext))',
        ] as $registration) {
            if (str_contains($text, $registration)) {
                continue;
            }

            [, , $functionEnd] = $this->locateKotlinFunction($text, 'registerPluginBridgeFunctions');
            $text = substr($text, 0, $functionEnd)."\n    {$registration}".substr($text, $functionEnd);
            $changed = true;
        }

        $this->writePatchResult($path, $text, $changed, 'native-secure-storage');
    }

    private function writeTextFile(string $path, string $content, string $prefix): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("[{$prefix}] error: unable to create directory [{$directory}]");
        }

        $normalizedContent = rtrim($content, "\n")."\n";
        $existingContent = file_exists($path) ? file_get_contents($path) : null;

        if ($existingContent === false) {
            throw new RuntimeException("[{$prefix}] error: unable to read [{$path}]");
        }

        if ($existingContent === $normalizedContent) {
            return;
        }

        file_put_contents($path, $normalizedContent);
    }
}
