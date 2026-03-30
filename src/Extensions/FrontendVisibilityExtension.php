<?php

namespace MoritzSauer\Instantsearch\Extensions;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\ListboxField;
use SilverStripe\Forms\Tab;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Group;
use SilverStripe\Security\InheritedPermissions;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;

class FrontendVisibilityExtension extends Extension
{
    private const ONLY_THESE_MEMBERS = 'OnlyTheseMembers';

    private static array $db = [
        'CanViewType' => "Enum('Anyone,LoggedInUsers,OnlyTheseUsers,OnlyTheseMembers,Inherit', 'Anyone')",
    ];

    private static array $many_many = [
        'ViewerGroups' => Group::class,
        'ViewerMembers' => Member::class,
    ];

    private static array $defaults = [
        'CanViewType' => InheritedPermissions::ANYONE,
    ];

    public function updateCMSFields(FieldList $fields): void
    {
        if (!$fields->fieldByName('Root.Visibility')) {
            $fields->addFieldToTab('Root', Tab::create('Visibility', 'Sichtbarkeit'));
        }

        $groupOptions = Group::get()->sort('Title')->map('ID', 'Title')->toArray();
        $memberOptions = Member::get()->sort('Email')->map('ID', 'Email')->toArray();

        $fields->addFieldsToTab('Root.Visibility', [
            DropdownField::create('CanViewType', 'Wer darf diesen Inhalt im Frontend sehen?', [
                InheritedPermissions::ANYONE => 'Jeder',
                InheritedPermissions::LOGGED_IN_USERS => 'Nur eingeloggte Nutzer',
                InheritedPermissions::ONLY_THESE_USERS => 'Nur diese Gruppen',
                self::ONLY_THESE_MEMBERS => 'Nur diese Mitglieder',
                InheritedPermissions::INHERIT => 'Vererben',
            ])->setDescription('Diese Einstellung steuert Frontend-Sichtbarkeit und Search-ACL in Typesense.'),
            ListboxField::create('ViewerGroups', 'Erlaubte Gruppen', $groupOptions)
                ->setDescription('Wird verwendet, wenn "Nur diese Gruppen" ausgewählt ist.'),
            ListboxField::create('ViewerMembers', 'Erlaubte Mitglieder', $memberOptions)
                ->setDescription('Wird verwendet, wenn "Nur diese Mitglieder" ausgewählt ist.'),
        ]);
    }

    /**
     * DataObject::canView() calls extension hooks first.
     * This enables frontend visibility rules for Event/VolunteerWork/DefectReport.
     */
    public function canView($member = null): ?bool
    {
        if (!$member) {
            $member = Security::getCurrentUser();
        }

        if (Permission::checkMember($member, 'ADMIN')) {
            return true;
        }

        $settings = $this->resolveVisibilitySettings();
        $type = $settings['type'];
        /** @var DataObject $source */
        $source = $settings['source'];

        switch ($type) {
            case InheritedPermissions::ANYONE:
                return true;

            case InheritedPermissions::LOGGED_IN_USERS:
                return $member instanceof Member;

            case InheritedPermissions::ONLY_THESE_USERS:
                if (!$member instanceof Member || !$source->hasMethod('ViewerGroups')) {
                    return false;
                }
                return $member->inGroups($source->ViewerGroups());

            case self::ONLY_THESE_MEMBERS:
                if (!$member instanceof Member || !$source->hasMethod('ViewerMembers')) {
                    return false;
                }
                return $source->ViewerMembers()->byID($member->ID) !== null;

            default:
                return false;
        }
    }

    /**
     * @return array{type: string, source: DataObject}
     */
    public function resolveVisibilitySettings(array $visited = []): array
    {
        /** @var DataObject $owner */
        $owner = $this->owner;

        $recordKey = $owner->ClassName . '#' . $owner->ID;
        if (in_array($recordKey, $visited, true)) {
            return [
                'type' => InheritedPermissions::ANYONE,
                'source' => $owner,
            ];
        }
        $visited[] = $recordKey;

        $type = (string)($owner->getField('CanViewType') ?: InheritedPermissions::ANYONE);
        if ($type !== InheritedPermissions::INHERIT) {
            return [
                'type' => $type,
                'source' => $owner,
            ];
        }

        $parent = $this->getVisibilityParentRecord();
        if (!$parent || !$parent->exists()) {
            return [
                'type' => InheritedPermissions::ANYONE,
                'source' => $owner,
            ];
        }

        if ($parent->hasMethod('resolveVisibilitySettings')) {
            return $parent->resolveVisibilitySettings($visited);
        }

        if ($parent instanceof SiteTree) {
            return $this->resolveSiteTreeVisibilitySettings($parent);
        }

        if ($parent->hasField('CanViewType') && $parent->hasMethod('ViewerGroups') && $parent->hasMethod('ViewerMembers')) {
            $parentType = (string)($parent->getField('CanViewType') ?: InheritedPermissions::ANYONE);
            if ($parentType === InheritedPermissions::INHERIT) {
                return [
                    'type' => InheritedPermissions::ANYONE,
                    'source' => $owner,
                ];
            }

            return [
                'type' => $parentType,
                'source' => $parent,
            ];
        }

        if ($parent->hasMethod('canView') && !$parent->canView()) {
            return [
                'type' => self::ONLY_THESE_MEMBERS,
                'source' => $owner,
            ];
        }

        return [
            'type' => InheritedPermissions::ANYONE,
            'source' => $owner,
        ];
    }

    /**
     * @return array{type: string, source: DataObject}
     */
    private function resolveSiteTreeVisibilitySettings(SiteTree $record): array
    {
        $type = (string)($record->CanViewType ?: InheritedPermissions::INHERIT);
        if ($type !== InheritedPermissions::INHERIT) {
            return [
                'type' => $type,
                'source' => $record,
            ];
        }

        $parent = $record->Parent();
        if ($parent && $parent->exists()) {
            return $this->resolveSiteTreeVisibilitySettings($parent);
        }

        return [
            'type' => InheritedPermissions::ANYONE,
            'source' => $record,
        ];
    }

    protected function getVisibilityParentRecord(): ?DataObject
    {
        /** @var DataObject $owner */
        $owner = $this->owner;
        if ($owner->hasMethod('getVisibilityParentRecord')) {
            $parent = $owner->getVisibilityParentRecord();
            if ($parent instanceof DataObject) {
                return $parent;
            }
        }

        return null;
    }
}
