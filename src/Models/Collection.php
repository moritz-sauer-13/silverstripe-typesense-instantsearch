<?php
/**
 * Silverstripe Typesense module
 * @license GPL3 With Attribution
 * @copyright Copyright (C) 2024 Elliot Sawyer
 */

namespace ElliotSawyer\SilverstripeTypesense;

use Exception;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\ListboxField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\RequiredFields;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDate;
use SilverStripe\ORM\FieldType\DBTime;
use Typesense\Exceptions\ObjectAlreadyExists;

/**
 * @property string $Name
 * @property string $DefaultSortingField
 * @property string $TokenSeperators
 * @property string $SymbolsToIndex
 * @property string $RecordClass
 * @property bool $Enabled
 * @property int $ImportLimit
 * @property int $ConnectionTimeout
 * @property string $ExcludedClasses
 * @property int $Sort
 * @method DataList|Field[] Fields()
 * @method DataList|Synonym[] Synonyms()
 */
class Collection extends DataObject
{
    private static $table_name = 'TypesenseCollection';
    private static $db = [
        'Name' => 'Varchar(64)',
        'DefaultSortingField' => 'Varchar(32)',
        'TokenSeperators' => 'Varchar(128)',
        'SymbolsToIndex' => 'Varchar(128)',
        'RecordClass' => 'Varchar(255)',
        'Enabled' => 'Boolean(1)',
        'ImportLimit' => 'Int(10000)',
        'ConnectionTimeout' => 'Int(2)',
        'ExcludedClasses' => 'Text',
        'Sort' => 'Int',
        'EnableNestedFields' => 'Boolean(0)',
    ];

    private static $has_many = [
        'Fields' => Field::class,
        'Synonyms' => Synonym::class,
    ];

    private static $summary_fields = [
        'Name',
        'ImportLimit',
        'ConnectionTimeout',
        'DefaultSortingField',
        'TokenSeperators',
        'SymbolsToIndex',
        'RecordClass',
        'Enabled.Nice' => 'Is enabled',
    ];

    private static $default_collection_fields = [
        ['name' => 'id', 'type' => 'int64'],
        ['name' => 'ClassName', 'type' => 'string', 'facet' => true],
        ['name' => 'LastEdited', 'type' => 'int64'],
        ['name' => 'Created', 'type' => 'int64'],
    ];

    private static $defaults = [
        'Enabled' => true,
        'ConnectionTimeout' => 2,
        'ImportLimit' => 10000,
    ];

    private static $cascade_deletes = ['Fields'];
    private static $default_sort = 'Sort ASC';

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        $fields = $this->getCMSMainFields($fields);

        $fieldsGridfield = $fields->dataFieldByName('Fields') ?? GridField::create('Fields', 'Fields', $this->Fields());
        $fieldsGridfield->setConfig(GridFieldConfig_RecordEditor::create());

        $synonymsField = $fields->dataFieldByName('Synonyms') ?? GridField::create('Synonyms', 'Synonyms', $this->Synonyms());
        $synonymsField->setConfig(GridFieldConfig_RecordEditor::create());
        return $fields;
    }

    public function getCMSMainFields($fields)
    {
        $recordClassDescription = _t(Collection::class . '.DESCRIPTION_RecordClass', 'The Silverstripe class (and subclasses) of DataObjects contained in this collection.  Only a single object type is supported.  To ensure data consistency it cannot be changed once set; you will need to delete the collection and build a new one');
        $fields->removeByName(['Name', 'DefaultSortingField', 'TokenSeperators', 'SymbolsToIndex', 'RecordClass', 'Enabled', 'ImportLimit', 'ConnectionTimeout', 'ExcludedClasses', 'Sort']);
        $fields->addFieldsToTab('Root.Main', [
            TextField::create('Name', _t(Collection::class . '.LABEL_Name', 'Name'))
                ->setDescription(_t(Collection::class . '.DESCRIPTION_Name', 'Name of the collection')),

            TextField::create('TokenSeperators', _t(Collection::class . '.LABEL_TokenSeperators', 'Token seperators'))
                ->setDescription(_t(Collection::class . '.DESCRIPTION_TokenSeperators', 'List of symbols or special characters to be used for splitting the text into individual words in addition to space and new-line characters. For e.g. you can add - (hyphen) to this list to make a word like non-stick to be split on hyphen and indexed as two separate words. <a href="https://typesense.org/docs/guide/tips-for-searching-common-types-of-data.html" target="_new">More info</a>')),

            TextField::create('SymbolsToIndex', _t(Collection::class . '.LABEL_SymbolsToIndex', 'Symbols to index'))
                ->setDescription(_t(Collection::class . '.DESCRIPTION_SymbolsToIndex', 'List of symbols or special characters to be indexed. For e.g. you can add + to this list to make the word c++ indexable verbatim. <a href="https://typesense.org/docs/guide/tips-for-searching-common-types-of-data.html" target="_new">More info</a>')),

            DropdownField::create(
                'DefaultSortingField',
                _t(Collection::class . '.LABEL_DefaultSortingField', 'Default sorting field'),
                $this->Fields()
                    ->exclude('type', 'auto')
                    ->map('name', 'name'),
                $this->DefaultSortingField
            )->setHasEmptyDefault(true)
                ->setDescription(_t(Collection::class . '.DESCRIPTION_DefaultSortingField', 'The name of an int32 / float field that determines the order in which the search results are ranked when a sort_by clause is not provided during searching. This field must indicate some kind of popularity. You cannot define a default sort on "auto" fields; it must be an explicitly defined field on your schema')),

            TextField::create('RecordClass', _t(Collection::class . '.LABEL_RecordClass', 'Record class name'))
                ->setDescription($recordClassDescription),
        ]);

        if ($this->ID && $this->RecordClass) {
            $excludedClassesList = array_map(function ($v) {
                return ClassInfo::shortName($v);
            }, ClassInfo::subclassesFor($this->RecordClass, false));

            $fields->addFieldsToTab('Root.Main', [

                ReadonlyField::create('RecordClass', _t(Collection::class . '.LABEL_RecordClass', 'Record class name'))
                    ->setDescription($recordClassDescription),

                CheckboxField::create('Enabled', _t(Collection::class . '.LABEL_Enabled', 'Enabled'))
                    ->setDescription(_t(Collection::class . '.DESCRIPTION_Enabled', 'When disabled, this collection will not be re-indexed. It is still available through the Typesense client. Do not rely on this for security.')),

                NumericField::create('ImportLimit', _t(Collection::class . '.LABEL_ImportLimit', 'Import limit'))
                    ->setDescription(_t(Collection::class . '.DESCRIPTION_ImportLimit', 'This is the number of documents that can be uploaded into Typesense at once when the sync task is run.  This is usually adjusted for speed and memory reasons, for example if your collection is very large (2M records) or the indexing task is being run on a system with limited memory.')),

                NumericField::create('ConnectionTimeout', _t(Collection::class . '.LABEL_ConnectionTimeout', 'Connection timeout'))
                    ->setDescription(_t(Collection::class . '.DESCRIPTION_ConnectionTimeout', 'When syncing a large dataset to Typesense the connector can time out.  You can adjust this timeout limit as-needed.  The units are measure in seconds.')),

                ListboxField::create('ExcludedClasses', _t(Collection::class . '.LABEL_ExcludedClasses', 'Excluded classes'), $excludedClassesList)
                    ->setDescription(_t(Collection::class . '.DESCRIPTION_ExcludedClasses', "By default, all subclasses of the record class are indexed. To exclude any classes, define an array of them on excludedClasses")),
            ]);
        }
        return $fields;
    }

    public function getCMSActions()
    {
        $actions = parent::getCMSActions();

        if ($this->ID && $this->Fields()->Count() > 0) {
            $actions->push(
                FormAction::create('syncWithTypesenseServer', _t(Collection::class . '.LABEL_syncWithTypesenseServer', 'Update collection in Typesense'))
                    ->addExtraClass('btn-outline-danger')
            );
            $actions->push(
                FormAction::create('deleteFromTypesenseServer', _t(Collection::class . '.LABEL_deleteFromTypesenseServer', 'Delete from Typesense'))
                    ->addExtraClass('btn-outline-danger')
            );
        }

        return $actions;
    }

    public function syncWithTypesenseServer(): string
    {
        $this->__createOrUpdateOnServer();
        return _t(Collection::class . '.MESSAGE_syncWithTypesenseServer', 'Synchronization of {name} with Typesense completed', ['name' => $this->Name]);
    }

    public function deleteFromTypesenseServer(): string
    {
        $this->__deleteOnServer();
        return _t(Collection::class . '.MESSAGE_deleteFromTypesenseServer', 'Collection {name} on Typesense server completed', ['name' => ($this->ID ? $this->Name : '')]);
    }

    public function onAfterBuild()
    {
        $copyright = (new Typesense())->CopyrightStatement();
        DB::alteration_message($copyright);
    }

    public static function find_or_make($name, $recordClass, $collectionFields): Collection
    {
        $collection = Collection::get()->find('Name', $name)
            ?: Collection::create(['Name' => $name]);

        if ($recordClass && class_exists($recordClass) && !$collection->RecordClass) {
            $collection->RecordClass = $recordClass;
        }
        $collection->DefaultSortingField = $collectionFields['default_sorting_field'] ?? null;
        $collection->TokenSeperators = $collectionFields['token_separators'] ?? null;
        $collection->SymbolsToIndex = $collectionFields['symbols_to_index'] ?? null;
        $collection->ImportLimit = $collectionFields['import_limit'] ?? 10000;
        $collection->ConnectionTimeout = $collectionFields['connection_timeout'] ?? 2;
        $collection->EnableNestedFields = $collectionFields['enable_nested_fields'] ?? 0;

        $excludedClasses = $collectionFields['excluded_classes'] ?? [];
        $collection->ExcludedClasses = mb_strtolower(json_encode($excludedClasses));
        $collection->write();

        if (isset($collectionFields['fields'])) {
            foreach ($collectionFields['fields'] as $fieldDefinition) {
                $field = Field::find_or_make($fieldDefinition, $collection->ID);
                $collection->Fields()->add($field);
            }
        }

        return $collection;
    }

    public function FieldsArray(): array
    {
        $arr = [];
        foreach ($this->Fields() as $field) {
            $schema = [
                'name' => $field->name ?? '.*',
                'type' => $field->type ?? 'auto',
                'facet' => (bool)$field->facet,
                'optional' => (bool)$field->optional,
                'index' => (bool)$field->index,
                'locale' => (string)$field->locale,
                'sort' => (bool)$field->sort,
                'store' => (bool)$field->store,
                'infix' => (bool)$field->infix,
                'num_dim' => (int)$field->num_dim,
                'vec_dist' => (string)$field->vec_dist,
            ];

            if ($field->hasMethod('updateTypesenseField')) {
                $augmentedField = $field->updateTypesenseField();
                if ($augmentedField) {
                    $schema += $augmentedField;
                }
            }
            $arr[] = $schema;
        }
        foreach ($this->config()->default_collection_fields as $field) {
            $arr[] = $field;
        }

        return $arr;
    }

    private function __createOrUpdateOnServer(): void
    {
        $client = Typesense::client();
        try {
            $schema = [
                'name' => $this->Name,
                'enable_nested_fields' => true,
                'fields' => $this->FieldsArray()
            ];

            if ($this->DefaultSortingField) {
                $schema['default_sorting_field'] = $this->DefaultSortingField;
            }
            if ($this->TokenSeperators) {
                $schema['token_separators'] = $this->TokenSeperators;
            }
            if ($this->SymbolsToIndex) {
                $schema['symbols_to_index'] = $this->SymbolsToIndex;
            }

            if ($this->checkExistence()) {
                $client->collections[$this->Name]->delete();
            }

            $client->collections->create($schema);
        } catch (ObjectAlreadyExists $e) {
            Injector::inst()->get(LoggerInterface::class)->info($e->getMessage());
        }
    }

    private function __deleteOnServer()
    {
        $client = Typesense::client();
        try {
            $client->collections[$this->Name]->delete();
        } catch (Exception $e) {
            Injector::inst()->get(LoggerInterface::class)->info($e->getMessage());
        }
    }

    public function onAfterDelete()
    {
        $this->deleteFromTypesenseServer();
    }

    public function getCMSValidator()
    {
        return RequiredFields::create([
            'Name',
            'RecordClass',
        ]);
    }

    public function validate(): \SilverStripe\ORM\ValidationResult
    {
        $valid = parent::validate();
        if (!class_exists($this->RecordClass)) {
            $valid->addFieldError('RecordClass', _t(Collection::class . '.FIELDERROR_RecordClass', 'Invalid class'));
        }
        return $valid;
    }

    public function onBeforeWrite()
    {
        parent::onBeforeWrite();
        $importLimit = (int)$this->ImportLimit ?? $this->config()->defaults['ImportLimit'];
        $connectionTimeout = (int)$this->ConnectionTimeout ?? $this->config()->defaults['ConnectionTimeout'];
        if ($importLimit <= 0) {
            $importLimit = 1;
        }
        if ($connectionTimeout <= 0) {
            $connectionTimeout = 1;
        }

        $this->ImportLimit = $importLimit;
        $this->ConnectionTimeout = $connectionTimeout;
    }

    public function import(): void
    {
        $limit = (int)($this->ImportLimit ?: 10000);
        $connection_timeout = (int)$this->ConnectionTimeout ?: 2;
        $client = Typesense::client($connection_timeout);
        $i = 0;
        $count = $this->getRecordsCount();
        DB::alteration_message(
            _t(Collection::class . '.IMPORT_Indexing', "Indexing {name}, (Limit: {limit}, Timeout: {timeout})", ['name' => $this->Name, 'limit' => $limit, 'timeout' => $connection_timeout])
        );
        if ($count === 0) {
            DB::alteration_message(
                '...'
                . _t(Collection::class . '.IMPORT_NoDocumentsFound', 'no documents found!')
            );
        }
        while ($records = $this->getRecords()->limit($limit, $i)) {
            $limitCount = $records->count();
            if ($limitCount == 0) {
                break;
            }
            $docs = [];

            $fieldsArray = $this->FieldsArray();
            foreach ($records as $record) {
                $data = [];
                if ($record->hasMethod('getTypesenseDocument')) {
                    $data = $record->getTypesenseDocument($fieldsArray);
                } else {
                    $data = $this->getTypesenseDocument($record, $fieldsArray);
                }
                if ($data) {
                    $docs[] = $data;
                }
            }

            $importResult = $client->collections[$this->Name]->documents->import($docs, ['action' => 'emplace']);
            $failedDocuments = 0;
            $firstImportError = '';
            $importEntries = [];
            if (is_array($importResult)) {
                $importEntries = $importResult;
            } else {
                foreach (preg_split('/\r\n|\r|\n/', (string)$importResult) as $line) {
                    $line = trim((string)$line);
                    if ($line === '') {
                        continue;
                    }

                    $entry = json_decode($line, true);
                    if (is_array($entry)) {
                        $importEntries[] = $entry;
                    }
                }
            }

            foreach ($importEntries as $entry) {
                if (array_key_exists('success', $entry) && $entry['success'] === false) {
                    $failedDocuments++;
                    if ($firstImportError === '' && !empty($entry['error'])) {
                        $firstImportError = (string)$entry['error'];
                    }
                }
            }
            DB::alteration_message(
                '...'
                . _t(Collection::class . '.IMPORT_AddedDocumentsToCollection', 'added [{limitcount} / {count}] documents to {name}', ['limitcount' => $i + $limitCount, 'count' => $count, 'name' => $this->Name])
            );
            if ($failedDocuments > 0) {
                DB::alteration_message(
                    sprintf('...failed %d documents for %s. First error: %s', $failedDocuments, $this->Name, $firstImportError ?: 'unknown'),
                    'error'
                );
            }

            $i += $limit;
        }
    }

    public function syncSynonyms(): void
    {
        foreach ($this->Synonyms() as $synonym) {
            if (!$synonym instanceof Synonym || !$synonym->exists()) {
                continue;
            }

            $synonym->syncWithTypesenseServer();
        }
    }

    protected function getRecords(): DataList
    {
        $records = null;
        $recordClass = $this->RecordClass;
        if (class_exists('SilverStripe\Subsites\Model\Subsite')) {
            \SilverStripe\Subsites\Model\Subsite::disable_subsite_filter();
        }
        if (class_exists('SilverStripe\Versioned\Versioned')) {
            $records = \SilverStripe\Versioned\Versioned::get_by_stage($recordClass, \SilverStripe\Versioned\Versioned::LIVE);
        } else {
            $records = $recordClass::get();
        }

        if ($this->ExcludedClasses) {
            $excludedClasses = json_decode($this->ExcludedClasses, true);
            if ($excludedClasses) {
                $excludedLookup = [];
                foreach (array_values($excludedClasses) as $excludedClass) {
                    $excludedClass = strtolower(trim((string)$excludedClass));
                    if ($excludedClass !== '') {
                        $excludedLookup[$excludedClass] = true;
                    }
                }

                $classNamesToExclude = [];
                if ($excludedLookup) {
                    $candidateClasses = array_unique(array_merge(
                        [$recordClass],
                        array_values(ClassInfo::subclassesFor($recordClass, false))
                    ));

                    foreach ($candidateClasses as $candidateClass) {
                        $lineage = array_merge(
                            [$candidateClass],
                            array_values(class_parents($candidateClass) ?: [])
                        );

                        foreach ($lineage as $ancestorClass) {
                            if (isset($excludedLookup[strtolower((string)$ancestorClass)])) {
                                $classNamesToExclude[] = $candidateClass;
                                break;
                            }
                        }
                    }
                }

                $classNamesToExclude = array_values(array_unique($classNamesToExclude));
                if ($classNamesToExclude) {
                    $records = $records->exclude('ClassName', $classNamesToExclude);
                }
            }
        }

        if ($recordClass::singleton()->hasField('ShowInSearch')) {
            $records = $records->exclude('ShowInSearch', 0);
        }

        return $records;
    }

    protected function getRecordsCount(): int
    {
        return $this->getRecords()->count();
    }

    public function getTypesenseDocument($record, $fieldsArray = []): array
    {
        $data = [];
        foreach ($fieldsArray as $field) {
            $name = $field['name'];
            $data[$name] = $record->__get($name);
            if (!$data[$name] && $record->hasMethod($name)) {
                $data[$name] = $record->$name();
            }
            if (strtolower($name) == 'id') {
                $data['id'] = (string)$record->ID;
            }
            if ($record->dbObject($name) instanceof DBDate || $record->dbObject($name) instanceof DBTime) {
                $data[$name] = strtotime($record->$name);
            }

            $data[$name] = $this->normalizeTypesenseFieldValue($data[$name] ?? null, $field);
        }

        return $data;
    }

    private function normalizeTypesenseFieldValue($value, array $field)
    {
        $type = strtolower((string)($field['type'] ?? ''));
        if ($type === '') {
            return $value;
        }

        if ($type === 'string') {
            if ($value === null || $value === '') {
                return $value;
            }

            return $this->normalizePlainTextString($value);
        }

        if ($type === 'bool') {
            if ($value === null || $value === '') {
                return null;
            }

            if (is_bool($value)) {
                return $value;
            }

            if (is_int($value) || is_float($value)) {
                return ((int)$value) === 1;
            }

            $normalized = strtolower(trim((string)$value));
            if ($normalized === '1' || $normalized === 'true' || $normalized === 'yes') {
                return true;
            }
            if ($normalized === '0' || $normalized === 'false' || $normalized === 'no') {
                return false;
            }

            return (bool)$value;
        }

        if ($type === 'int32' || $type === 'int64') {
            if ($value === null || $value === '') {
                return null;
            }
            return (int)$value;
        }

        if ($type === 'float') {
            if ($value === null || $value === '') {
                return null;
            }

            if (is_string($value)) {
                $value = str_replace(',', '.', $value);
            }

            return (float)$value;
        }

        if ($type === 'string[]') {
            if ($value === null || $value === '') {
                return [];
            }

            if (is_array($value)) {
                $values = array_map(function ($item): string {
                    return $this->normalizePlainTextString($item);
                }, $value);

                return array_values(array_filter($values, static function (string $item): bool {
                    return $item !== '';
                }));
            }

            $item = $this->normalizePlainTextString($value);

            return $item === '' ? [] : [$item];
        }

        return $value;
    }

    private function normalizePlainTextString($value): string
    {
        $text = trim((string)$value);
        if ($text === '') {
            return '';
        }

        $text = preg_replace('/<\s*br\s*\/?\s*>/iu', "\n", $text) ?? $text;
        $text = preg_replace('/<\s*\/p\s*>/iu', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xc2\xa0", ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    public function checkExistence()
    {
        $exists = false;
        $client = Typesense::client();

        try {
            $client->collections[$this->Name]->retrieve();
            $exists = true;
        } catch (Exception $e) {
            $exists = false;
        }

        return $exists;
    }
}
