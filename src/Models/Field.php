<?php
/**
 * Silverstripe Typesense module
 * @license GPL3 With Attribution
 * @copyright Copyright (C) 2024 Elliot Sawyer
 */

namespace ElliotSawyer\SilverstripeTypesense;

use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;

/**
 * @property string $name
 * @property string $type
 * @property bool $facet
 * @property bool $optional
 * @property bool $index
 * @property bool $sort
 * @property bool $store
 * @property bool $infix
 * @property string $locale
 * @property bool $stem
 * @property int $CollectionID
 * @method Collection Collection()
 */
class Field extends DataObject
{
    private static $table_name = 'TypesenseField';

    private static $field_types = [
        'string',
        'string[]',
        'int32',
        'int32[]',
        'int64',
        'int64[]',
        'float',
        'float[]',
        'bool',
        'bool[]',
        'geopoint',
        'geopoint[]',
        'object',
        'object[]',
        'string*',
        'image',
        'auto',
    ];

    private static $db = [
        'name' => 'Varchar(255)',
        'type' => 'Varchar(10)',
        'facet' => 'Boolean(0)',
        'optional' => 'Boolean(0)',
        'index' => 'Boolean(1)',
        'sort' => 'Boolean(1)',
        'store' => 'Boolean(1)',
        'infix' => 'Boolean(0)',
        'locale' => 'Varchar(2)',
        'num_dim' => 'Decimal(10,8)',
        'vec_dist' => 'Enum("cosine,ip","cosine")',
        'stem' => 'Boolean(0)',
    ];

    private static $has_one = [
        'Collection' => Collection::class
    ];

    private static $summary_fields = [
        'name' => 'Name',
        'type' => 'Type',
        'facet.Nice' => 'Facet',
        'index.Nice' => 'Index',
        'locale' => 'Locale',
        'store.Nice' => 'Store',
        'optional.Nice' => 'Optional',
        'sort.Nice' => 'Sort',
        'infix.Nice' => 'Infix',
        'stem.Nice' => 'Stemming',
        'num_dim' => 'Number of dimensions',
        'vec_dist' => 'Vector distance calculation'
    ];

    private static $defaults = [
        'facet' => false,
        'optional' => false,
        'index' => true,
        'locale' => 'en',
        'sort' => true,
        'store' => true,
        'infix' => false,
        'stem' => 0,
        'num_dim' => 0,
        'vec_dist' => 'cosine'
    ];

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['CollectionID', 'name', 'type', 'facet', 'optional', 'index', 'locale', 'sort', 'store', 'infix', 'stem', 'num_dim', 'vec_dist']);
        $types = array_combine(
            $this->config()->field_types,
            $this->config()->field_types
        );
        $fields->addFieldsToTab('Root.Main', [
            TextField::create('name', _t(Field::class . '.LABEL_name', 'Name'))
                ->setDescription(_t(Field::class . '.DESCRIPTION_name', "Name of the field.")),
            DropdownField::create('type', _t(Field::class . '.LABEL_type', 'Type'), $types)
                ->setDescription(_t(Field::class . '.DESCRIPTION_type', "The data type of the field. An explanation for each field is <a href='https://typesense.org/docs/26.0/api/collections.html#field-types' target='_new'>here</a>")),
            CheckboxField::create('facet', _t(Field::class . '.LABEL_facet', 'Facet'))
                ->setDescription(_t(Field::class . '.DESCRIPTION_facet', "Enables faceting on the field. Default: false.")),
            CheckboxField::create('optional', _t(Field::class . '.LABEL_optional', 'Optional'))
                ->setDescription(_t(Field::class . '.DESCRIPTION_optional', "When set to true, the field can have empty, null or missing values. Default: false.")),
            CheckboxField::create('index', _t(Field::class . '.LABEL_index', 'Index'))
                ->setDescription(_t(Field::class . '.DESCRIPTION_index', "When set to false, the field will not be indexed in any in-memory index (e.g. search/sort/filter/facet). Default: true.")),
            Locale::getCMSFields('locale'),
            CheckboxField::create('sort', _t(Field::class . '.LABEL_sort', 'Sort'))
                ->setDescription(_t(Field::class . '.DESCRIPTION_sort', "When set to true, the field will be sortable. 'auto' fields cannot be sorted. Default: true for numbers, false otherwise.")),
            CheckboxField::create('store', _t(Field::class . '.LABEL_store', 'Store'))
                ->setDescription(_t(Field::class . '.DESCRIPTION_store', "When set to false, the field value will not be stored on disk.  Default: true.")),
            CheckboxField::create('infix', _t(Field::class . '.LABEL_infix', 'Infix'))
                ->setDescription(_t(Field::class . '.DESCRIPTION_infix', "When set to true, the field value can be infix-searched. Incurs significant memory overhead. Default: false.")),
            CheckboxField::create('stem', _t(Field::class . '.LABEL_stem', 'Stem'))
                ->setDescription(_t(Field::class . '.DESCRIPTION_stem', "Stemming allows you to handle common word variations (singular / plurals, tense changes) of the same root word. For example: searching for walking, will also return results with walk, walked, walks, etc when stemming is enabled. This feature uses <a href='https://snowballstem.org/'>Snowball Stemmer</a>: language selection for stemmer is automatically made from the value of the locale property associated with the field (only 'en' is tested but other languages may work). Default: false")),
            NumericField::create('num_dim', _t(Field::class . '.LABEL_num_dim', 'Number of Dimensions'))
                ->setDescription(_t(Field::class . '.DESCRIPTION_num_dim', "Set this to a non-zero value to treat a field of type float[] as a vector field. Facets cannot be used on vector fields.")),
            CheckboxField::create('stem', _t(Field::class . '.LABEL_vec_dist', 'Vector Distance Formula'))
                ->setDescription(_t(Field::class . '.DESCRIPTION_vec_dist', "he distance metric to be used for vector search. Default: cosine. You can also use ip for inner product.")),
        ]);
        return $fields;
    }

    public function validate(): \SilverStripe\ORM\ValidationResult
    {
        $valid = parent::validate();
        if (!in_array($this->type, $this->config()->field_types)) {
            $valid->addFieldError('type', _t(Field::class . '.FIELDERROR_type', 'Invalid field type'));
        }

        if ($this->type == 'string[]' && $this->sort == true) {
            $valid->addFieldError('sort', _t(Field::class . '.FIELDERROR_sort', 'Sorting on string[] and string* is not supported'));
        }
        return $valid;
    }

    public static function find_or_make($fieldDefinition, $parentID): Field
    {
        $field = Field::get()->filter($fieldDefinition + ['CollectionID' => $parentID])->first()
            ?: Field::create($fieldDefinition + ['CollectionID' => $parentID]);
        if (!$field->ID) {
            $field->write();
        }

        return $field;
    }

    public function onBeforeWrite()
    {
        parent::onBeforeWrite();
        if ($this->type == 'auto') {
            $this->sort = false;
        }
        if ($this->facet == true) {
            $this->optional = true;
        }
        if ($this->type == 'string*' || $this->type == 'string[]') {
            $this->sort = false;
        }

        if (!$this->locale) {
            $this->locale = 'en';
        }

        if ($this->num_dim > 0) {
            $this->facet = false;
        }
    }
}
