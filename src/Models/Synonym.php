<?php
/**
 * Silverstripe Typesense module
 * @license LGPL3 With Attribution
 * @copyright Copyright (C) 2024 Elliot Sawyer
 */

namespace ElliotSawyer\SilverstripeTypesense;

use Psr\Log\LoggerInterface;
use SilverStripe\Core\Environment;
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
        $this->syncWithTypesenseServer();
    }

    public function syncWithTypesenseServer(): void
    {
        if (!$this->TypesenseIdentifier) {
            return;
        }

        $collection = $this->Collection();
        if (!$collection || !$collection->Name) {
            return;
        }

        $client = Typesense::client();
        $payload = $this->getTypesensePayload();
        if (empty($payload['synonyms'])) {
            return;
        }

        try {
            $client->collections[$collection->Name]->synonyms->upsert(
                $this->TypesenseIdentifier,
                $payload
            );
        } catch (ObjectNotFound $exception) {
            // Typesense v30+ replaced collection-level synonyms with synonym sets.
            $this->upsertWithSynonymSet($collection->Name, $payload);
        }
    }

    public function getTypesensePayload(): array
    {
        $synonyms = array_values($this->synonyms->Items()->map('Title')->toArray());
        $payload = [
            'synonyms' => $synonyms,
        ];

        if ($this->root) {
            $payload['root'] = $this->root;
        }

        $symbols = array_values($this->symbols_to_index->Items()->map('Title')->toArray());
        if ($symbols) {
            $payload['symbols_to_index'] = $symbols;
        }

        if ($this->locale) {
            $payload['locale'] = $this->locale;
        }

        return $payload;
    }

    public function onBeforeDelete()
    {
        parent::onBeforeDelete();
        $collectionName = (string)$this->Collection()->Name;
        if ($collectionName === '' || !$this->TypesenseIdentifier) {
            return;
        }

        try {
            $client = Typesense::client();
            $client->collections[$collectionName]
                ->getSynonyms()[$this->TypesenseIdentifier]
                ->delete();
        } catch (ObjectNotFound $onf) {
            if (!$this->deleteFromSynonymSet($collectionName)) {
                Injector::inst()->get(LoggerInterface::class)
                    ->info(_t(Synonym::class . '.ERROR_AlreadyGone', 'The synonym you tried to delete is already removed from Typesense'));
            }
        }
    }

    private function upsertWithSynonymSet(string $collectionName, array $payload): void
    {
        $setName = $this->getSynonymSetName($collectionName);

        $this->callTypesenseApi('PUT', '/synonym_sets/' . rawurlencode($setName), ['items' => []]);
        $this->callTypesenseApi(
            'PUT',
            '/synonym_sets/' . rawurlencode($setName) . '/items/' . rawurlencode($this->TypesenseIdentifier),
            $payload
        );

        $this->ensureSynonymSetLinkedToCollection($collectionName, $setName);
    }

    private function deleteFromSynonymSet(string $collectionName): bool
    {
        $setName = $this->getSynonymSetName($collectionName);
        try {
            $this->callTypesenseApi(
                'DELETE',
                '/synonym_sets/' . rawurlencode($setName) . '/items/' . rawurlencode($this->TypesenseIdentifier)
            );
            return true;
        } catch (\RuntimeException $exception) {
            if ((int)$exception->getCode() === 404) {
                return false;
            }
            throw $exception;
        }
    }

    private function ensureSynonymSetLinkedToCollection(string $collectionName, string $setName): void
    {
        $collectionData = $this->callTypesenseApi('GET', '/collections/' . rawurlencode($collectionName));
        $synonymSets = $collectionData['synonym_sets'] ?? [];
        if (!is_array($synonymSets)) {
            $synonymSets = [];
        }

        $normalized = [];
        foreach ($synonymSets as $existingSetName) {
            $existingSetName = trim((string)$existingSetName);
            if ($existingSetName !== '') {
                $normalized[$existingSetName] = true;
            }
        }

        if (isset($normalized[$setName])) {
            return;
        }

        $normalized[$setName] = true;

        $this->callTypesenseApi(
            'PATCH',
            '/collections/' . rawurlencode($collectionName),
            ['synonym_sets' => array_values(array_keys($normalized))]
        );
    }

    private function getSynonymSetName(string $collectionName): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '_', $collectionName) . '_synonyms_index';
    }

    private function callTypesenseApi(string $method, string $path, ?array $payload = null): array
    {
        $server = rtrim((string)Environment::getEnv('TYPESENSE_SERVER'), '/');
        $apiKey = (string)Environment::getEnv('TYPESENSE_API_KEY');

        if ($server === '' || $apiKey === '') {
            throw new \RuntimeException('Missing Typesense configuration');
        }

        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cURL extension is required for synonym set operations');
        }

        $url = $server . '/' . ltrim($path, '/');
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Unable to initialize cURL request');
        }

        $headers = [
            'X-TYPESENSE-API-KEY: ' . $apiKey,
            'Content-Type: application/json',
        ];

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 15,
        ];

        if ($payload !== null) {
            $jsonPayload = json_encode($payload);
            if ($jsonPayload === false) {
                curl_close($ch);
                throw new \RuntimeException('Unable to encode Typesense payload');
            }

            $options[CURLOPT_POSTFIELDS] = $jsonPayload;
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Typesense API request failed: ' . $error);
        }

        $decodedResponse = trim((string)$response) === ''
            ? []
            : json_decode((string)$response, true);

        if ($status >= 400) {
            $message = 'Typesense API request failed with HTTP ' . $status;
            if (is_array($decodedResponse) && !empty($decodedResponse['message'])) {
                $message = (string)$decodedResponse['message'];
            }

            throw new \RuntimeException($message, $status);
        }

        return is_array($decodedResponse) ? $decodedResponse : [];
    }
}
