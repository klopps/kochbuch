<?php

declare(strict_types=1);

namespace Kochbuch\Service;

use Kochbuch\Exception\TranslationKeyMismatchException;
use Kochbuch\Exception\ValidationException;

/**
 * Write-path counterpart to the read-only Translator, backing the admin
 * "/admin/translate" tool (todo.md "Admin-Oberfläche") - mirrors YTAN's
 * src/Service/TranslationRepository.php. Kept to exactly the two locales
 * Kochbuch ships (resources/i18n/{de,en}.json), same as YTAN's own
 * hardcoded en/de save() signature - todo.md's "beliebige Sprachen" is
 * about the admin being able to translate the UI into other languages
 * eventually, not about this tool itself being locale-count-agnostic yet.
 */
final class TranslationRepository
{
    public function __construct(private readonly string $resourcesDir)
    {
    }

    /**
     * @return array<string, string>
     */
    public function load(string $locale): array
    {
        $file = $this->pathFor($locale);
        if (!is_file($file)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Refuses to save if the two locales' key sets diverge, unless $force
     * is true, and refuses any value containing "</script" (translation
     * values are embedded in a <script> tag on every page load - see
     * templates/app.php's window.KOCHBUCH_TRANSLATIONS injection).
     *
     * @param array<string, string> $en
     * @param array<string, string> $de
     */
    public function save(array $en, array $de, bool $force = false): void
    {
        $this->assertNoScriptBreakout($en);
        $this->assertNoScriptBreakout($de);

        if (!$force) {
            $mismatch = $this->diffKeys($en, $de);
            if ($mismatch['only_in_en'] !== [] || $mismatch['only_in_de'] !== []) {
                throw new TranslationKeyMismatchException($mismatch['only_in_en'], $mismatch['only_in_de']);
            }
        }

        $this->write('en', $en);
        $this->write('de', $de);
    }

    /**
     * @param array<string, string> $en
     * @param array<string, string> $de
     * @return array{only_in_en: string[], only_in_de: string[]}
     */
    public function diffKeys(array $en, array $de): array
    {
        return [
            'only_in_en' => array_values(array_diff(array_keys($en), array_keys($de))),
            'only_in_de' => array_values(array_diff(array_keys($de), array_keys($en))),
        ];
    }

    /**
     * @param array<string, string> $map
     */
    private function assertNoScriptBreakout(array $map): void
    {
        foreach ($map as $key => $value) {
            if (is_string($value) && stripos($value, '</script') !== false) {
                throw new ValidationException(
                    "Translation \"$key\" contains \"</script\" - this would break out of the <script> tag every page injects translations into. Rephrase it.",
                    'translation.unsafe_value'
                );
            }
        }
    }

    /**
     * @param array<string, string> $map
     */
    private function write(string $locale, array $map): void
    {
        $json = json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->pathFor($locale), $json . "\n");
    }

    private function pathFor(string $locale): string
    {
        // basename() - defense in depth, even though $locale here only ever
        // comes from this class's own hardcoded 'en'/'de' call sites.
        return $this->resourcesDir . '/' . basename($locale) . '.json';
    }
}
