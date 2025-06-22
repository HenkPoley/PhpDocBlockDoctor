<?php
namespace Pitfalls\PropertyNullableType;

class Template
{
    private ?Translate $translator;

    public function __construct()
    {
        $this->translator = new Translate();
    }

    public function getEntityPropertyTranslation(): void
    {
        $this->translator->getLanguage()->getPreferredLanguages();
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
     * @return array
     */
    public function getPreferredLanguages(): array
    {
        throw new \ArithmeticError();
    }
}
