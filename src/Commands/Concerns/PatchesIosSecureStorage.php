<?php

declare(strict_types=1);

namespace Goodm4ven\NativePatches\Commands\Concerns;

use RuntimeException;

trait PatchesIosSecureStorage
{
    private function patchIosSecureStorage(string $pluginsRegistrationPath, string $functionsPath): void
    {
        $this->writeTextFile(
            $pluginsRegistrationPath,
            <<<'SWIFT'
import Foundation

// AUTO-GENERATED FILE - DO NOT EDIT
// This file is overwritten during the build process with plugin registrations

func registerPluginBridgeFunctions() {
    let registry = BridgeFunctionRegistry.shared

    registry.register("SecureStorage.Set", function: SecureStorageFunctions.Set())
    registry.register("SecureStorage.Get", function: SecureStorageFunctions.Get())
    registry.register("SecureStorage.Delete", function: SecureStorageFunctions.Delete())
}
SWIFT,
            'native-secure-storage',
        );

        $this->writeTextFile(
            $functionsPath,
            <<<'SWIFT'
import Foundation
import Security

// MARK: - Secure Storage Namespace

/// Functions related to secure native storage.
/// Namespace: "SecureStorage.*"
enum SecureStorageFunctions {
    private static let serviceName = "nativephp.mobile.secure-storage"

    private static func normalizedKey(from parameters: [String: Any]) throws -> String {
        guard let rawKey = parameters["key"] else {
            throw BridgeError.invalidParameters("key is required")
        }

        let key = String(describing: rawKey).trimmingCharacters(in: .whitespacesAndNewlines)

        if key.isEmpty {
            throw BridgeError.invalidParameters("key is required")
        }

        return key
    }

    private static func normalizedValue(from parameters: [String: Any]) -> String? {
        guard let rawValue = parameters["value"], !(rawValue is NSNull) else {
            return nil
        }

        let value = String(describing: rawValue)

        return value.isEmpty ? nil : value
    }

    private static func keychainQuery(for key: String) -> [String: Any] {
        [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: serviceName,
            kSecAttrAccount as String: key,
            kSecReturnData as String: true,
            kSecMatchLimit as String: kSecMatchLimitOne,
        ]
    }

    private static func upsertValue(_ value: String?, for key: String) throws {
        let encodedValue = value?.data(using: .utf8)
        let query = keychainQuery(for: key)

        if let encodedValue {
            let updateAttributes: [String: Any] = [
                kSecValueData as String: encodedValue,
            ]

            let status = SecItemUpdate(query as CFDictionary, updateAttributes as CFDictionary)

            if status == errSecSuccess {
                return
            }

            if status != errSecItemNotFound {
                throw BridgeError.executionFailed("Failed to store secure value: \(secCopyErrorMessage(status))")
            }

            var addQuery = query
            addQuery[kSecValueData as String] = encodedValue
            addQuery.removeValue(forKey: kSecReturnData as String)
            addQuery.removeValue(forKey: kSecMatchLimit as String)

            let addStatus = SecItemAdd(addQuery as CFDictionary, nil)

            if addStatus != errSecSuccess {
                throw BridgeError.executionFailed("Failed to store secure value: \(secCopyErrorMessage(addStatus))")
            }
        } else {
            let status = SecItemDelete(query as CFDictionary)

            if status != errSecSuccess && status != errSecItemNotFound {
                throw BridgeError.executionFailed("Failed to delete secure value: \(secCopyErrorMessage(status))")
            }
        }
    }

    private static func readValue(for key: String) throws -> String? {
        var query = keychainQuery(for: key)
        query[kSecReturnData as String] = true
        query[kSecMatchLimit as String] = kSecMatchLimitOne

        var result: CFTypeRef?
        let status = SecItemCopyMatching(query as CFDictionary, &result)

        if status == errSecItemNotFound {
            return nil
        }

        if status != errSecSuccess {
            throw BridgeError.executionFailed("Failed to read secure value: \(secCopyErrorMessage(status))")
        }

        guard let data = result as? Data else {
            return nil
        }

        return String(data: data, encoding: .utf8)
    }

    private static func secCopyErrorMessage(_ status: OSStatus) -> String {
        let message = SecCopyErrorMessageString(status, nil)
        return message as String? ?? "OSStatus \(status)"
    }

    class Set: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let key = try SecureStorageFunctions.normalizedKey(from: parameters)
            let value = SecureStorageFunctions.normalizedValue(from: parameters)

            print("🔐 SecureStorage.Set writing key: \(key)")

            try SecureStorageFunctions.upsertValue(value, for: key)

            return ["success": true]
        }
    }

    class Get: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let key = try SecureStorageFunctions.normalizedKey(from: parameters)

            print("🔐 SecureStorage.Get reading key: \(key)")

            return ["value": try SecureStorageFunctions.readValue(for: key) as Any]
        }
    }

    class Delete: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let key = try SecureStorageFunctions.normalizedKey(from: parameters)

            print("🔐 SecureStorage.Delete deleting key: \(key)")

            try SecureStorageFunctions.upsertValue(nil, for: key)

            return ["success": true]
        }
    }
}
SWIFT,
            'native-secure-storage',
        );
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
