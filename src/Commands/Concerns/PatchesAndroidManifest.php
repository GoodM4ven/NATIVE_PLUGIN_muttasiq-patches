<?php

declare(strict_types=1);

namespace Goodm4ven\NativePatches\Commands\Concerns;

use RuntimeException;

trait PatchesAndroidManifest
{
    private function patchAndroidManifest(string $path): void
    {
        if (! file_exists($path)) {
            $this->info("[native-no-backup] skip missing: {$path}");

            return;
        }

        $text = file_get_contents($path);
        if ($text === false) {
            throw new RuntimeException("[native-no-backup] error: unable to read {$path}");
        }

        $changed = false;

        $changed = $this->upsertAndroidApplicationAttribute($text, 'allowBackup', 'false') || $changed;
        $changed = $this->upsertAndroidApplicationAttribute($text, 'fullBackupContent', 'false') || $changed;
        $changed = $this->upsertAndroidApplicationAttribute(
            $text,
            'dataExtractionRules',
            '@xml/data_extraction_rules',
        ) || $changed;

        $this->writePatchResult($path, $text, $changed, 'native-no-backup');
    }

    private function patchAndroidBackupRules(
        string $dataExtractionRulesPath,
        string $backupRulesPath,
    ): void {
        $dataExtractionRules = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<data-extraction-rules>
    <cloud-backup>
        <exclude domain="file" path="." />
        <exclude domain="database" path="." />
        <exclude domain="sharedpref" path="." />
        <exclude domain="external" path="." />
        <exclude domain="root" path="." />
    </cloud-backup>
    <device-transfer>
        <exclude domain="file" path="." />
        <exclude domain="database" path="." />
        <exclude domain="sharedpref" path="." />
        <exclude domain="external" path="." />
        <exclude domain="root" path="." />
    </device-transfer>
</data-extraction-rules>
XML;

        $backupRules = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<full-backup-content>
    <exclude domain="file" path="." />
    <exclude domain="database" path="." />
    <exclude domain="sharedpref" path="." />
    <exclude domain="external" path="." />
    <exclude domain="root" path="." />
</full-backup-content>
XML;

        $this->writeAndroidXmlFile($dataExtractionRulesPath, $dataExtractionRules, 'native-no-backup');
        $this->writeAndroidXmlFile($backupRulesPath, $backupRules, 'native-no-backup');
    }

    private function writeAndroidXmlFile(string $path, string $content, string $prefix): void
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
            $this->info("[{$prefix}] already ok: {$path}");

            return;
        }

        file_put_contents($path, $normalizedContent);
        $this->info("[{$prefix}] patched: {$path}");
    }

    private function upsertAndroidApplicationAttribute(
        string &$manifestText,
        string $attributeName,
        string $attributeValue,
    ): bool {
        $attributePattern = '/android:'.preg_quote($attributeName, '/').'="([^"]*)"/';

        if (preg_match($attributePattern, $manifestText, $matches) === 1) {
            if (isset($matches[1]) && $matches[1] === $attributeValue) {
                return false;
            }

            $updatedManifest = preg_replace(
                $attributePattern,
                'android:'.$attributeName.'="'.$attributeValue.'"',
                $manifestText,
                1,
                $replacements,
            );

            if (! is_string($updatedManifest) || $replacements < 1) {
                throw new RuntimeException(
                    "[native-no-backup] error: unable to update android:{$attributeName}",
                );
            }

            $manifestText = $updatedManifest;

            return true;
        }

        $applicationTagPattern = '/<application\b([^>]*)>/m';
        $updatedManifest = preg_replace(
            $applicationTagPattern,
            '<application$1'."\n        ".'android:'.$attributeName.'="'.$attributeValue.'">',
            $manifestText,
            1,
            $replacements,
        );

        if (! is_string($updatedManifest) || $replacements < 1) {
            throw new RuntimeException(
                "[native-no-backup] error: unable to insert android:{$attributeName}",
            );
        }

        $manifestText = $updatedManifest;

        return true;
    }
}
