<?php

declare(strict_types=1);

namespace Kochbuch\Exception;

/**
 * Thrown by TranslationRepository::save() when the "en"/"de" key sets
 * diverge and the caller didn't pass force=true - the admin translate UI
 * surfaces getPayload()'s two lists and lets the admin confirm anyway
 * (re-submitting with force=true), rather than silently letting one
 * locale's file drift out of sync with the other's key set.
 */
class TranslationKeyMismatchException extends ValidationException
{
    /**
     * @param string[] $onlyInEn
     * @param string[] $onlyInDe
     */
    public function __construct(private readonly array $onlyInEn, private readonly array $onlyInDe)
    {
        parent::__construct('Translation keys differ between locales.', 'translation.key_mismatch');
    }

    /**
     * @return array{only_in_en: string[], only_in_de: string[]}
     */
    public function getPayload(): array
    {
        return ['only_in_en' => $this->onlyInEn, 'only_in_de' => $this->onlyInDe];
    }
}
