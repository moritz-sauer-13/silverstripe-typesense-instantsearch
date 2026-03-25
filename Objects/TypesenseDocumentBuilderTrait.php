<?php

namespace MoritzSauer\Instantsearch\Objects;

use MoritzSauer\Instantsearch\Services\SearchVisibilityService;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDate;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBTime;

trait TypesenseDocumentBuilderTrait
{
    public function getTypesenseDocument(array $fields = []): array
    {
        /** @var SearchVisibilityService $visibility */
        $visibility = Injector::inst()->get(SearchVisibilityService::class);

        $data = [];

        foreach ($fields as $field) {
            $name = $field['name'] ?? null;
            if (!$name) {
                continue;
            }

            if ($name === 'id') {
                $data['id'] = (string)$this->ID;
                continue;
            }

            if ($name === 'AccessibleTo') {
                $data['AccessibleTo'] = $visibility->getRecordTokens($this);
                continue;
            }

            if ($name === 'SearchVisible') {
                $data['SearchVisible'] = $visibility->isSearchVisible($this);
                continue;
            }

            $data[$name] = $this->resolveTypesenseFieldValue($name);
        }

        if (isset($data['Link']) && is_string($data['Link'])) {
            $data['Link'] = $this->sanitizeSearchLink($data['Link']);
        }

        $data['id'] = $data['id'] ?? (string)$this->ID;
        $data['ClassName'] = $data['ClassName'] ?? $this->ClassName;
        $data['Created'] = $data['Created'] ?? strtotime((string)$this->Created ?: 'now');
        $data['LastEdited'] = $data['LastEdited'] ?? strtotime((string)$this->LastEdited ?: 'now');
        $data['AccessibleTo'] = $visibility->getRecordTokens($this);
        $data['SearchVisible'] = $visibility->isSearchVisible($this);

        return $data;
    }

    private function resolveTypesenseFieldValue(string $name)
    {
        $value = null;

        if ($this->hasField($name)) {
            $value = $this->getField($name);
        }

        if (($value === null || $value === '') && $this->hasMethod($name)) {
            $value = $this->{$name}();
        }

        $getter = 'get' . $name;
        if (($value === null || $value === '') && $this->hasMethod($getter)) {
            $value = $this->{$getter}();
        }

        if ($name === 'Created' || $name === 'LastEdited') {
            return strtotime((string)$this->getField($name));
        }

        $dbObject = $this->dbObject($name);
        if ($dbObject instanceof DBDate || $dbObject instanceof DBTime || $dbObject instanceof DBDatetime) {
            return $value ? strtotime((string)$value) : null;
        }

        if ($value instanceof DataObject) {
            return $value->exists() ? $value->ID : null;
        }

        if ($name === 'Link' && is_string($value)) {
            return $this->sanitizeSearchLink($value);
        }

        return $value;
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
