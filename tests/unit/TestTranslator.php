<?php

namespace Ernestdefoe\Steward\Tests\unit;

use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * The extension's own locale file, loaded the way Flarum loads it (one ICU
 * MessageFormat catalogue), so the unit tests read the real English.
 */
final class TestTranslator
{
    public static function make(): Translator
    {
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', __DIR__.'/../../locale/en.yml', 'en', 'messages+intl-icu');

        return $translator;
    }
}
