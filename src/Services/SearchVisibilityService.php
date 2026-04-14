<?php

namespace MoritzSauer\Instantsearch\Services;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\InheritedPermissions;
use SilverStripe\Security\Member;

class SearchVisibilityService
{
    private const ONLY_THESE_MEMBERS = 'OnlyTheseMembers';

    /**
     * @return string[]
     */
    public function getPrincipalTokens(?Member $member): array
    {
        $tokens = ['public'];

        if ($member) {
            $tokens[] = 'authenticated';
            $tokens[] = 'member_' . $member->ID;

            foreach ($member->Groups() as $group) {
                $tokens[] = 'group_' . $group->ID;
            }
        }

        return array_values(array_unique($tokens));
    }

    public function buildScopedFilter(?Member $member): string
    {
        $tokens = $this->getPrincipalTokens($member);
        $escapedTokens = array_map([$this, 'escapeFilterToken'], $tokens);

        return sprintf(
            'SearchVisible:=true && AccessibleTo:=[%s]',
            implode(',', $escapedTokens)
        );
    }

    /**
     * @return string[]
     */
    public function getRecordTokens(DataObject $record): array
    {
        [$type, $source] = $this->resolveRecordVisibility($record);

        switch ($type) {
            case InheritedPermissions::ANYONE:
                return ['public'];

            case InheritedPermissions::LOGGED_IN_USERS:
                return ['authenticated'];

            case InheritedPermissions::ONLY_THESE_USERS:
                if ($source->hasMethod('ViewerGroups')) {
                    return array_map(
                        static fn($id): string => 'group_' . $id,
                        array_map('intval', $source->ViewerGroups()->column('ID'))
                    );
                }
                return [];

            case self::ONLY_THESE_MEMBERS:
                if ($source->hasMethod('ViewerMembers')) {
                    return array_map(
                        static fn($id): string => 'member_' . $id,
                        array_map('intval', $source->ViewerMembers()->column('ID'))
                    );
                }
                return [];

            default:
                return ['public'];
        }
    }

    public function isSearchVisible(DataObject $record): bool
    {
        $visible = true;

        if ($record->hasField('ShowInSearch')) {
            $showInSearch = $record->getField('ShowInSearch');
            if ($showInSearch !== null && $showInSearch !== '') {
                $visible = $visible && (bool)$showInSearch;
            }
        }

        if ($record->hasMethod('isFrontendSearchVisible')) {
            $visible = $visible && (bool)$record->isFrontendSearchVisible();
        }

        return $visible;
    }

    /**
     * @return array{0: string, 1: DataObject}
     */
    private function resolveRecordVisibility(DataObject $record, array $visited = []): array
    {
        $recordKey = $record->ClassName . '#' . $record->ID;
        if (in_array($recordKey, $visited, true)) {
            return [InheritedPermissions::ANYONE, $record];
        }
        $visited[] = $recordKey;

        if ($record instanceof SiteTree) {
            return $this->resolveSiteTreeVisibility($record, $visited);
        }

        if ($record->hasMethod('resolveVisibilitySettings')) {
            $settings = $record->resolveVisibilitySettings($visited);
            $type = (string)($settings['type'] ?? InheritedPermissions::ANYONE);
            $source = $settings['source'] ?? $record;
            if ($source instanceof DataObject) {
                return [$type, $source];
            }
        }

        if ($record->hasField('CanViewType') && $record->hasMethod('ViewerGroups') && $record->hasMethod('ViewerMembers')) {
            $type = (string)($record->getField('CanViewType') ?: InheritedPermissions::ANYONE);
            if ($type === InheritedPermissions::INHERIT && $record->hasMethod('getVisibilityParentRecord')) {
                $parent = $record->getVisibilityParentRecord();
                if ($parent instanceof DataObject && $parent->exists()) {
                    return $this->resolveRecordVisibility($parent, $visited);
                }
                return [InheritedPermissions::ANYONE, $record];
            }

            return [$type, $record];
        }

        if ($record->hasMethod('getVisibilityParentRecord')) {
            $parent = $record->getVisibilityParentRecord();
            if ($parent instanceof DataObject && $parent->exists()) {
                return $this->resolveRecordVisibility($parent, $visited);
            }
        }

        return [InheritedPermissions::ANYONE, $record];
    }

    /**
     * @return array{0: string, 1: DataObject}
     */
    private function resolveSiteTreeVisibility(SiteTree $record, array $visited = []): array
    {
        $type = (string)($record->CanViewType ?: InheritedPermissions::INHERIT);
        if ($type !== InheritedPermissions::INHERIT) {
            return [$type, $record];
        }

        $parent = $record->Parent();
        if ($parent && $parent->exists()) {
            return $this->resolveSiteTreeVisibility($parent, $visited);
        }

        return [InheritedPermissions::ANYONE, $record];
    }

    private function escapeFilterToken(string $value): string
    {
        return '`' . str_replace('`', '\\`', $value) . '`';
    }
}
