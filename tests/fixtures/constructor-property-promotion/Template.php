<?php

namespace Pitfalls\ConstructorPropertyPromotion;

class Template
{
    public function __construct(private Translate $translator)
    {
    }

    public function getEntityPropertyTranslation(): void
    {
        $tryLanguages = $this->translator->getLanguage()->getPreferredLanguages();
    }
}

class Translate
{
    private Language $language;

    public function __construct()
    {
        $this->language = new Language();
    }

    public function getLanguage(): Language
    {
        return $this->language;
    }
}

class Language
{
    /**
     * @throws \ArithmeticError
     * @return array
     */
    public function getPreferredLanguages(): array
    {
        throw new \ArithmeticError();
    }
}
