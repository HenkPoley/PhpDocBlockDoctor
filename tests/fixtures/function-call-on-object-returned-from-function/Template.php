<?php

namespace Pitfalls\FunctionCallOnObjectReturnedFromFunction;

/** Found in \SimpleSAML\XHTML\Template::getEntityPropertyTranslation() */
class Template
{
    /**
     * @var Translate
     */
    private Translate $translator;

    public function __construct()
    {
        $this->translator = new Translate();
    }

    /**
     * Make sure we actually understand where the function ->getPreferredLanguages() lives
     */
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
     * Just some Error gets thrown, could be any Throwable.
     *
     * @return array
     */
    public function getPreferredLanguages(): array
    {
        throw new \ArithmeticError();
    }
}