<?php

declare(strict_types=1);

namespace Goodm4ven\NativePatches\Commands\Concerns;

use RuntimeException;

trait PatchesEdgeComponents
{
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
        );

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
        );

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

        $changed = $updated;

        $changed = $this->replaceOnceOrError(
            $text,
            "        \$target['data'] = array_merge(\$data, [\n            'children' => \$target['data']['children'] ?? [],\n        ]);\n",
            "        \$target['data'] = array_merge(\$data, [\n            'children' => \$children,\n        ]);\n",
            'Edge children assignment',
            "            'children' => \$children,",
        ) || $changed;

        $this->writePatchResult($path, $text, $changed, 'native-edge');
    }
}
