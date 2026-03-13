<?php

declare(strict_types=1);

namespace Goodm4ven\NativePatches\Commands\Concerns;

use RuntimeException;

trait InteractsWithPatchFiles
{
    private function buildKotlinFunctionDefinition(
        string $name,
        string $body,
        string $visibility = 'private',
        string $returnType = ''
    ): string {
        $lines = explode("\n", $body);
        $indented = [];
        foreach ($lines as $line) {
            $indented[] = $line === '' ? '' : '        '.$line;
        }

        return "    {$visibility} fun {$name}(){$returnType} {\n".implode("\n", $indented)."\n    }\n";
    }

    private function buildComposableDefinition(string $name, string $body): string
    {
        $lines = explode("\n", $body);
        $indented = [];
        foreach ($lines as $line) {
            $indented[] = $line === '' ? '' : '        '.$line;
        }

        return "    @Composable\n    private fun {$name}() {\n".implode("\n", $indented)."\n    }\n";
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function setSwiftFunctionBody(string $text, string $funcName, string $newBody): array
    {
        [$indent, $start, $end] = $this->locateSwiftFunction($text, $funcName);
        $bodyIndent = $indent.'    ';
        $lines = explode("\n", $newBody);
        $indented = [];
        foreach ($lines as $line) {
            $indented[] = $line === '' ? '' : $bodyIndent.$line;
        }

        $replacement = substr($text, 0, $start + 1)."\n".implode("\n", $indented)."\n".$indent.'}'.substr($text, $end + 1);

        return [$replacement, $replacement !== $text];
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function setKotlinFunctionBody(string $text, string $funcName, string $newBody): array
    {
        [$indent, $start, $end] = $this->locateKotlinFunction($text, $funcName);
        $bodyIndent = $indent.'    ';
        $lines = explode("\n", $newBody);
        $indented = [];
        foreach ($lines as $line) {
            $indented[] = $line === '' ? '' : $bodyIndent.$line;
        }

        $replacement = substr($text, 0, $start + 1)."\n".implode("\n", $indented)."\n".$indent.'}'.substr($text, $end + 1);

        return [$replacement, $replacement !== $text];
    }

    /**
     * @return array{0: string, 1: int, 2: int}
     */
    private function locateKotlinFunction(string $text, string $funcName): array
    {
        $pattern = '/^([ \t]*)(?:(?:private|public|protected|override)\s+)*fun\s+'.preg_quote($funcName, '/').'\s*\(/m';
        if (! preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            throw new RuntimeException("function '{$funcName}' not found");
        }

        $indent = $matches[1][0];
        $matchPos = $matches[0][1];
        $matchLen = strlen($matches[0][0]);
        $start = strpos($text, '{', $matchPos + $matchLen);
        if ($start === false) {
            throw new RuntimeException("function '{$funcName}' has no opening body brace");
        }

        $depth = 1;
        $i = $start + 1;
        $len = strlen($text);
        $inString = false;
        $inTriple = false;
        $escape = false;
        $end = null;

        while ($i < $len) {
            if ($inTriple) {
                if (substr($text, $i, 3) === '"""') {
                    $inTriple = false;
                    $i += 3;

                    continue;
                }
            } elseif ($inString) {
                if ($escape) {
                    $escape = false;
                } elseif ($text[$i] === '\\') {
                    $escape = true;
                } elseif ($text[$i] === '"') {
                    $inString = false;
                }
            } else {
                if (substr($text, $i, 3) === '"""') {
                    $inTriple = true;
                    $i += 3;

                    continue;
                }
                if ($text[$i] === '"') {
                    $inString = true;
                } elseif ($text[$i] === '{') {
                    $depth++;
                } elseif ($text[$i] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $end = $i;
                        break;
                    }
                }
            }
            $i++;
        }

        if ($end === null) {
            throw new RuntimeException("function '{$funcName}' has no closing body brace");
        }

        return [$indent, $start, $end];
    }

    /**
     * @return array{0: string, 1: int, 2: int}
     */
    private function locateSwiftFunction(string $text, string $funcName): array
    {
        $pattern = '/^([ \t]*)(?:(?:@\w+\s+)|(?:private|public|internal|fileprivate|override|final|mutating|nonmutating|class|static)\s+)*func\s+'.preg_quote($funcName, '/').'\s*\(/m';
        if (! preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            throw new RuntimeException("function '{$funcName}' not found");
        }

        $indent = $matches[1][0];
        $matchPos = $matches[0][1];
        $matchLen = strlen($matches[0][0]);
        $start = strpos($text, '{', $matchPos + $matchLen);
        if ($start === false) {
            throw new RuntimeException("function '{$funcName}' has no opening body brace");
        }

        $depth = 1;
        $i = $start + 1;
        $len = strlen($text);
        $inString = false;
        $inTriple = false;
        $escape = false;
        $end = null;

        while ($i < $len) {
            if ($inTriple) {
                if (substr($text, $i, 3) === '"""') {
                    $inTriple = false;
                    $i += 3;

                    continue;
                }
            } elseif ($inString) {
                if ($escape) {
                    $escape = false;
                } elseif ($text[$i] === '\\') {
                    $escape = true;
                } elseif ($text[$i] === '"') {
                    $inString = false;
                }
            } else {
                if (substr($text, $i, 3) === '"""') {
                    $inTriple = true;
                    $i += 3;

                    continue;
                }
                if ($text[$i] === '"') {
                    $inString = true;
                } elseif ($text[$i] === '{') {
                    $depth++;
                } elseif ($text[$i] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $end = $i;
                        break;
                    }
                }
            }
            $i++;
        }

        if ($end === null) {
            throw new RuntimeException("function '{$funcName}' has no closing body brace");
        }

        return [$indent, $start, $end];
    }

    private function insertImport(string &$text, string $importLine, string $after, string $label): bool
    {
        if (str_contains($text, $importLine)) {
            return false;
        }

        if (! str_contains($text, $after)) {
            throw new RuntimeException("import anchor not found for {$label}");
        }

        $text = $this->replaceFirst($text, $after, $after."\n".$importLine);

        return true;
    }

    private function removeLine(string &$text, string $line): bool
    {
        if (! str_contains($text, $line)) {
            return false;
        }

        $text = $this->replaceFirst($text, $line, '');

        return true;
    }

    private function replaceOnceOrError(
        string &$text,
        string $old,
        string $new,
        string $label,
        ?string $alreadyContains = null,
    ): bool {
        if (str_contains($text, $old)) {
            $text = $this->replaceFirst($text, $old, $new);

            return true;
        }

        if ($alreadyContains !== null && str_contains($text, $alreadyContains)) {
            return false;
        }

        if (str_contains($text, $new)) {
            return false;
        }

        throw new RuntimeException("pattern not found for {$label}");
    }

    private function replaceRegexOnceOrError(
        string &$text,
        string $pattern,
        string $replacement,
        string $label,
        ?string $alreadyContains = null,
    ): bool {
        $count = 0;
        $updated = preg_replace('/'.$pattern.'/ms', $replacement, $text, 1, $count);
        if ($updated !== null && $count > 0) {
            $text = $updated;

            return true;
        }

        if ($alreadyContains !== null && str_contains($text, $alreadyContains)) {
            return false;
        }

        if (str_contains($text, $replacement)) {
            return false;
        }

        throw new RuntimeException("regex pattern not found for {$label}");
    }

    private function insertBeforeOrError(string &$text, string $anchor, string $insert, string $label): bool
    {
        if (str_contains($text, $insert)) {
            return false;
        }

        if (! str_contains($text, $anchor)) {
            throw new RuntimeException("pattern not found for {$label}");
        }

        $text = $this->replaceFirst($text, $anchor, $insert."\n".$anchor);

        return true;
    }

    private function replaceFirst(string $text, string $search, string $replace): string
    {
        $pos = strpos($text, $search);
        if ($pos === false) {
            return $text;
        }

        return substr_replace($text, $replace, $pos, strlen($search));
    }

    private function writePatchResult(string $path, string $text, bool $changed, string $prefix): void
    {
        if ($changed) {
            file_put_contents($path, $text);
            $this->info("[{$prefix}] patched: {$path}");
        } else {
            $this->info("[{$prefix}] already ok: {$path}");
        }
    }
}
