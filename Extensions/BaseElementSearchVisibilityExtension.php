<?php

namespace MoritzSauer\Instantsearch\Extensions;

use MoritzSauer\Instantsearch\Services\SearchVisibilityService;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDate;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBTime;

class BaseElementSearchVisibilityExtension extends Extension
{
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

            $data[$name] = $value;
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

        $parent = $this->getVisibilityParentRecord();
        if ($parent instanceof DataObject) {
            return $visibility->isSearchVisible($parent);
        }

        return $visibility->isSearchVisible($this->getOwner());
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
