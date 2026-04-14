<?php

namespace MoritzSauer\Instantsearch\Extensions;

use MoritzSauer\Instantsearch\Services\SearchVisibilityService;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDate;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBTime;

class BaseElementSearchVisibilityExtension extends Extension
{
    private static array $db = [
        'ShowInSearch' => 'Boolean(1)',
    ];

    private static array $defaults = [
        'ShowInSearch' => 1,
    ];

    public function updateCMSFields(FieldList $fields): void
    {
        $showInSearchField = CheckboxField::create(
            'ShowInSearch',
            _t(SiteTree::class . '.SHOWINSEARCH', 'Show in search?')
        );

        if ($fields->fieldByName('Root.Settings')) {
            $fields->addFieldToTab('Root.Settings', $showInSearchField, 'ExtraClass');
            return;
        }

        $fields->addFieldToTab('Root.Main', $showInSearchField);
    }

    public function getTypesenseDocument(array $fields = []): array
    {
        $owner = $this->getOwner();
        $data = [];

        foreach ($fields as $field) {
            $name = $field['name'] ?? null;
            if (!$name) {
                continue;
            }

            if ($name === 'id') {
                $data['id'] = (string)$owner->ID;
                continue;
            }

            if ($name === 'AccessibleTo') {
                $data['AccessibleTo'] = $this->AccessibleTo();
                continue;
            }

            if ($name === 'SearchVisible') {
                $data['SearchVisible'] = $this->SearchVisible();
                continue;
            }

            $value = null;
            if ($owner->hasField($name)) {
                $value = $owner->getField($name);
            }
            if (($value === null || $value === '') && $owner->hasMethod($name)) {
                $value = $owner->{$name}();
            }

            $getter = 'get' . $name;
            if (($value === null || $value === '') && $owner->hasMethod($getter)) {
                $value = $owner->{$getter}();
            }

            if ($name === 'Created' || $name === 'LastEdited') {
                $value = strtotime((string)$owner->getField($name));
            } else {
                $dbObject = $owner->dbObject($name);
                if ($dbObject instanceof DBDate || $dbObject instanceof DBTime || $dbObject instanceof DBDatetime) {
                    $value = $value ? strtotime((string)$value) : null;
                }
            }

            if ($name === 'Link' && is_string($value)) {
                $value = $this->sanitizeSearchLink($value);
            }

            $data[$name] = $this->normalizeTypesenseFieldValue($value, $field);
        }

        if (isset($data['Link']) && is_string($data['Link'])) {
            $data['Link'] = $this->sanitizeSearchLink($data['Link']);
        }

        $data['id'] = $data['id'] ?? (string)$owner->ID;
        $data['ClassName'] = $data['ClassName'] ?? $owner->ClassName;
        $data['Created'] = $data['Created'] ?? strtotime((string)$owner->Created ?: 'now');
        $data['LastEdited'] = $data['LastEdited'] ?? strtotime((string)$owner->LastEdited ?: 'now');
        $data['AccessibleTo'] = $this->AccessibleTo();
        $data['SearchVisible'] = $this->SearchVisible();

        return $data;
    }

    public function getVisibilityParentRecord(): ?DataObject
    {
        if ($this->getOwner()->hasMethod('getPage')) {
            $page = $this->getOwner()->getPage();
            if ($page instanceof DataObject && $page->exists()) {
                return $page;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    public function AccessibleTo(): array
    {
        /** @var SearchVisibilityService $visibility */
        $visibility = Injector::inst()->get(SearchVisibilityService::class);

        $parent = $this->getVisibilityParentRecord();
        if ($parent instanceof DataObject) {
            return $visibility->getRecordTokens($parent);
        }

        return $visibility->getRecordTokens($this->getOwner());
    }

    public function SearchVisible(): bool
    {
        /** @var SearchVisibilityService $visibility */
        $visibility = Injector::inst()->get(SearchVisibilityService::class);

        $ownerIsVisible = $visibility->isSearchVisible($this->getOwner());
        if (!$ownerIsVisible) {
            return false;
        }

        $parent = $this->getVisibilityParentRecord();
        if ($parent instanceof DataObject) {
            return $visibility->isSearchVisible($parent);
        }

        return true;
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

    private function sanitizeSearchLink(string $link): string
    {
        $link = trim($link);
        if ($link === '') {
            return '';
        }

        $parts = parse_url($link);
        if ($parts === false) {
            return $this->stripStageParameter($link);
        }

        $params = [];
        if (!empty($parts['query'])) {
            parse_str((string)$parts['query'], $params);
            foreach (array_keys($params) as $key) {
                if (strcasecmp((string)$key, 'stage') === 0) {
                    unset($params[$key]);
                }
            }
        }

        $query = http_build_query($params);

        $result = '';
        if (isset($parts['scheme'])) {
            $result .= $parts['scheme'] . '://';
        }
        if (isset($parts['user'])) {
            $result .= $parts['user'];
            if (isset($parts['pass'])) {
                $result .= ':' . $parts['pass'];
            }
            $result .= '@';
        }
        if (isset($parts['host'])) {
            $result .= $parts['host'];
        }
        if (isset($parts['port'])) {
            $result .= ':' . $parts['port'];
        }
        if (isset($parts['path'])) {
            $result .= $parts['path'];
        }
        if ($query !== '') {
            $result .= '?' . $query;
        }
        if (isset($parts['fragment'])) {
            $result .= '#' . $parts['fragment'];
        }

        if ($result === '') {
            return $this->stripStageParameter($link);
        }

        return $result;
    }

    private function stripStageParameter(string $link): string
    {
        $result = preg_replace('/([?&])stage=[^&#]*/i', '$1', $link) ?? $link;
        $result = str_replace('?&', '?', $result);
        $result = preg_replace('/[?&]$/', '', $result) ?? $result;

        return $result;
    }
}
