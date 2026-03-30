<?php
/**
 * Silverstripe Typesense module
 * @license LGPL3 With Attribution
 * @copyright Copyright (C) 2024 Elliot Sawyer
 */

namespace ElliotSawyer\SilverstripeTypesense;

use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\RequiredFields;
use SilverStripe\ORM\DataObject;
use Typesense\Exceptions\ObjectNotFound;

/**
 * @property string $TypesenseIdentifier
 * @property string $synonyms
 * @property string $root
 * @property string $locale
 * @property string $symbols_to_index
 * @property int $CollectionID
 * @method Collection Collection()
 */
class Synonym extends DataObject
{
    private static $table_name = 'TypesenseSynonym';

    private static $db = [
        'TypesenseIdentifier' => 'Varchar(255)',
        'synonyms' => 'MultiValueField',
        'root' => 'Varchar(255)',
        'locale' => 'Varchar(2)',
        'symbols_to_index' => 'MultiValueField',
    ];

    private static $summary_fields = [
        'TypesenseIdentifier' => 'Typesense Synonym ID',
        'getSynonymsCSV(synonyms)' => 'Synonyms',
        'root' => 'Root word',
        'locale' => 'Locale',
        'getSynonymsCSV(symbols_to_index)' => 'Symbols to index',
    ];

    private static $has_one = [
        'Collection' => Collection::class
    ];

    public function getSynonymsCSV($field)
    {
        $obj = $this->dbObject($field);
        $csv = $obj->csv();
        return $csv;
    }

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        $fields->removeByName('CollectionID');
        if ($this->ID) {
            $fields->dataFieldByName('TypesenseIdentifier')->setReadonly(true)
                ->setDescription(
                    _t(Synonym::class . '.identifier_active', 'This field cannot be updated once changed, you need to delete the synonym.')
                );
        } else {
            $fields->dataFieldByName('TypesenseIdentifier')
                ->setDescription(
                    _t(Synonym::class . '.identifier', 'This can be anything but must be unique within the Typesense collection. You will get an error if there is a duplicate. If left blank, one will be automatically generated for you.')
                );
        }

        $fields->dataFieldByName('synonyms')->setDescription(
            _t(Synonym::class . '.synonyms', 'This is an array of words that should be considered as synonyms. They can be interchangeable unless a root word is present, in which case, the relationship is only one-way')
        );

        $fields->dataFieldByName('root')->setDescription(
            _t(Synonym::class . '.root', 'For 1-way synonyms, indicates the root word that words in the synonyms parameter map to.')
        );

        $localeField = Locale::getCMSFields('locale');

        $fields->replaceField('locale', $localeField);
        $fields->dataFieldByName('locale')->setDescription(
            _t(Synonym::class . '.locale', 'Locale for the synonym, leave blank to use the standard tokenizer.')
        );

        $fields->dataFieldByName('symbols_to_index')->setDescription(
            _t(Synonym::class . '.symbols_to_index', 'By default, special characters are dropped from synonyms. Use this attribute to specify which special characters should be indexed as is.')
        );

        return $fields;
    }

    public function getCMSValidator()
    {
        return RequiredFields::create(['synonyms']);
    }

    public function validate()
    {
        $valid = parent::validate();
        if ($this->TypesenseIdentifier) {
            $existingSynonym = Synonym::get()
                ->filter([
                    'TypesenseIdentifier' => $this->TypesenseIdentifier,
                    'CollectionID' => $this->CollectionID
                ])
                ->exclude([
                    'ID' => $this->ID
                ])->first();
            if ($existingSynonym && $existingSynonym->ID) {
                $valid->addFieldError(
                    'TypesenseIdentifier',
                    _t(Synonym::class . '.ERROR_TypesenseIdentifierExists', 'The TypesenseIdentifier already exists, edit that one or choose a different one.')
                );
            }
        }
        return $valid;
    }

    public function onBeforeWrite()
    {
        parent::onBeforeWrite();
        if (!$this->TypesenseIdentifier) {
            $this->TypesenseIdentifier = sodium_bin2hex(random_bytes(8));
        }

        if (!$this->locale) {
            $this->locale = 'en';
        }
    }

    public function onAfterWrite()
    {
        parent::onAfterWrite();
        $client = Typesense::client();
        $synonyms = array_values($this->synonyms->Items()->map('Title')->toArray());
        $payload['synonyms'] = $synonyms;
        if ($this->root) {
            $payload['root'] = $this->root;
        }

        if ($this->symbols_to_index) {
            $symbols = array_values($this->symbols_to_index->Items()->map('Title')->toArray());

            if ($symbols) {
                $payload['symbols_to_index'] = $symbols;
            }
        }

        if ($this->locale) {
            $payload['locale'] = $this->locale;
        }

        $client->collections[$this->Collection()->Name]->synonyms->upsert(
            $this->TypesenseIdentifier,
            $payload
        );
    }

    public function onBeforeDelete()
    {
        parent::onBeforeDelete();
        try {
            $client = Typesense::client();
            $client->collections[$this->Collection()->Name]
                ->getSynonyms()[$this->TypesenseIdentifier]
                ->delete();
        } catch (ObjectNotFound $onf) {
            Injector::inst()->get(LoggerInterface::class)
                ->info(_t(Synonym::class . '.ERROR_AlreadyGone', 'The synonym you tried to delete is already removed from Typesense'));
        }
    }
}
