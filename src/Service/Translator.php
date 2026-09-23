<?php

declare(strict_types=1);

namespace Kochbuch\Service;

/**
 * Loads resources/i18n/{locale}.json (a flat key => string map, shared as
 * the single source of truth between server-rendered PHP templates and the
 * client - see public/js/i18n.js, which reads the same resolved locale's
 * content via window.KOCHBUCH_TRANSLATIONS) and resolves t($key, $vars)
 * against it, falling back to the fallback locale's file for any key
 * missing in the active one, and finally to the key itself if missing
 * everywhere. Read-only; the admin-only write counterpart
 * (TranslationRepository, for a future /admin/translate tool) doesn't exist
 * yet - see CLAUDE.md.
 */
final class Translator
{
    /** @var array<string, string> */
    private array $strings;

    /** @var array<string, string> */
    private array $fallbackStrings;

    public function __construct(
        string $resourcesDir,
        private readonly string $locale,
        private readonly string $fallbackLocale = 'en',
    ) {
        $this->strings = self::loadFile($resourcesDir, $this->locale);
        $this->fallbackStrings = $this->locale === $this->fallbackLocale
            ? $this->strings
            : self::loadFile($resourcesDir, $this->fallbackLocale);
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /**
     * @param array<string, scalar> $vars
     */
    public function t(string $key, array $vars = []): string
    {
        $template = $this->strings[$key] ?? $this->fallbackStrings[$key] ?? $key;

        if ($vars === []) {
            return $template;
        }

        $replacements = [];
        foreach ($vars as $name => $value) {
            $replacements['{' . $name . '}'] = (string) $value;
        }

        return strtr($template, $replacements);
    }

    /**
     * Everything currently loaded for $this->locale (not the fallback) -
     * what the home route injects as window.KOCHBUCH_TRANSLATIONS for
     * public/js/i18n.js's t() to read client-side.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->strings;
    }

    /**
     * @return array<string, string>
     */
    private static function loadFile(string $resourcesDir, string $locale): array
    {
        // basename() - $locale ultimately traces back to a cookie/header
        // value (see resolveLocale() below); never let it walk the path.
        $path = $resourcesDir . '/' . basename($locale) . '.json';
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Pure/testable locale resolution: stored preference (the "settings"
     * cookie public/js/settings.js writes for theme/language, read here
     * server-side so the very first response is already in the right
     * language) takes priority, then the browser's Accept-Language header
     * (first supported tag wins), then $default.
     *
     * @param string[] $supportedLocales
     */
    public static function resolveLocale(
        array $supportedLocales,
        ?string $settingsCookieValue,
        ?string $acceptLanguageHeader,
        string $default = 'de',
    ): string {
        $supportedLocales = array_values(array_filter(array_map('trim', $supportedLocales)));
        if ($supportedLocales === []) {
            return $default;
        }
        if (!in_array($default, $supportedLocales, true)) {
            $default = $supportedLocales[0];
        }

        if ($settingsCookieValue !== null && $settingsCookieValue !== '') {
            $decoded = json_decode($settingsCookieValue, true);
            if (is_array($decoded) && isset($decoded['language']) && is_string($decoded['language'])
                && in_array($decoded['language'], $supportedLocales, true)) {
                return $decoded['language'];
            }
        }

        if ($acceptLanguageHeader !== null && $acceptLanguageHeader !== '') {
            foreach (explode(',', $acceptLanguageHeader) as $part) {
                $tag = strtolower(trim(explode(';', $part)[0] ?? ''));
                $lang = substr($tag, 0, 2);
                if (in_array($lang, $supportedLocales, true)) {
                    return $lang;
                }
            }
        }

        return $default;
    }
}
