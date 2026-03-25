<?php
/**
 * Silverstripe Typesense module
 * @license GPL3 With Attribution
 * @copyright Copyright (C) 2024 Elliot Sawyer
 */

namespace ElliotSawyer\SilverstripeTypesense;

use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FormField;

class Locale
{
    public static function getCMSFields($name = 'locale')
    {
        return DropdownField::create($name, FormField::name_to_label($name))
            ->setSource([
                'ja' => _t(Locale::class . '.ja', 'Japanese'),
                'zh' => _t(Locale::class . '.zh', 'Chinese'),
                'ko' => _t(Locale::class . '.ko', 'Korean'),
                'th' => _t(Locale::class . '.th', 'Thai'),
                'el' => _t(Locale::class . '.el', 'Greek'),
                'ru' => _t(Locale::class . '.ru', 'Russian'),
                'sr' => _t(Locale::class . '.sr', 'Serbian / Cyrillic'),
                'uk' => _t(Locale::class . '.uk', 'Ukrainian'),
                'be' => _t(Locale::class . '.be', 'Belarusian'),
            ])->setEmptyString(
                _t(Locale::class . '.emptystring', 'Default: en')
            )
            ->setDescription(
                _t(Locale::class . '.description', 'The default tokenizer that Typesense uses works for most languages, especially ones that separate words by spaces. Typesense has locale specific customizations for the these languages. Defaults to en, which also broadly supports most European languages.')
            );
    }
}
