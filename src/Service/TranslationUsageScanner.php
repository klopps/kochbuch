<?php

declare(strict_types=1);

namespace Kochbuch\Service;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Static, grep-like scan for every `t('some.key')` call site (PHP's
 * Translator::t()/$translator->t() and JS's t(), same function name in
 * both - see resources/i18n's doc-comments), so the admin translate UI can
 * show where a key is used, or flag it as unreferenced (a candidate for
 * deletion). Only finds call sites where the key is a literal string, not
 * one built from a variable (e.g. helper.js's `t(VISIBILITY_META[x].labelKey)`)
 * - those keys will show up as "unused" even though they're read
 * dynamically, a known limitation of a purely static scan.
 */
final class TranslationUsageScanner
{
    private const SCAN_DIRS = ['public/js', 'templates'];

    public function __construct(private readonly string $rootDir)
    {
    }

    /**
     * @return array<string, array{file: string, line: int}[]>
     */
    public function scan(): array
    {
        $usages = [];

        foreach (self::SCAN_DIRS as $dir) {
            foreach ($this->filesIn($this->rootDir . '/' . $dir) as $file) {
                $lines = file($file);
                if ($lines === false) {
                    continue;
                }
                foreach ($lines as $lineNumber => $line) {
                    if (preg_match_all('/\bt\(\s*[\'"]([a-zA-Z0-9_.]+)[\'"]/', $line, $matches)) {
                        foreach ($matches[1] as $key) {
                            $usages[$key][] = [
                                'file' => str_replace('\\', '/', ltrim(substr($file, strlen($this->rootDir)), '/\\')),
                                'line' => $lineNumber + 1,
                            ];
                        }
                    }
                }
            }
        }

        return $usages;
    }

    /**
     * @return string[]
     */
    private function filesIn(string $path): array
    {
        if (!is_dir($path)) {
            return [];
        }

        $result = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $fileInfo) {
            if (in_array($fileInfo->getExtension(), ['js', 'php'], true)) {
                $result[] = $fileInfo->getPathname();
            }
        }

        return $result;
    }
}
