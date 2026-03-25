<?php
/**
 * Silverstripe Typesense module
 * @license GPL3 With Attribution
 * @copyright Copyright (C) 2024 Elliot Sawyer
 */

namespace ElliotSawyer\SilverstripeTypesense;

use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataExtension;
use SilverStripe\Versioned\Versioned;
use Typesense\Exceptions\ObjectNotFound;
use Typesense\Exceptions\RequestMalformed;

/**
 * @property DataObject|DocumentUpdate $owner
 */
class DocumentUpdate extends Extension
{
    private function getOwnerClassName(): string
    {
        return $this->owner->ClassName ?: get_class($this->owner);
    }

    private function isValidTypesenseClass(string $ownerClass): bool
    {
        foreach ($this->getValidTypesenseClasses() as $recordClass) {
            if ($ownerClass === $recordClass || is_subclass_of($ownerClass, $recordClass)) {
                return true;
            }
        }

        return false;
    }

    private function getValidTypesenseClasses()
    {
        $cache = Injector::inst()->get(CacheInterface::class . '.TypesenseCache');
        if (!($classes = $cache->get('Classes'))) {
            $classes = Collection::get()->column('RecordClass');
            $cache->set('Classes', $classes, 86400);
        }
        return $classes;
    }

    private function isVersionedRecord(): bool
    {
        return class_exists(Versioned::class)
            && $this->owner->hasExtension(Versioned::class);
    }

    private function getVersionedStage(): ?string
    {
        if (!$this->isVersionedRecord()) {
            return null;
        }

        $stage = $this->owner->getSourceQueryParam('Versioned.stage');
        if (!$stage && class_exists(Versioned::class)) {
            $stage = Versioned::get_stage();
        }

        return $stage ?: null;
    }

    private function shouldSyncOnWrite(): bool
    {
        if (!$this->isVersionedRecord()) {
            return true;
        }

        return $this->getVersionedStage() === Versioned::LIVE;
    }

    private function shouldSyncDelete(): bool
    {
        if (!$this->isVersionedRecord()) {
            return true;
        }

        if ($this->getVersionedStage() === Versioned::LIVE) {
            return true;
        }

        if ($this->owner->hasMethod('isPublished')) {
            return !(bool)$this->owner->isPublished();
        }

        return false;
    }

    private function getTypesenseCollection()
    {
        $classNames = array_merge([$this->getOwnerClassName()], class_parents($this->getOwnerClassName()) ?: []);
        foreach (array_unique($classNames) as $className) {
            $collection = Collection::get()->find('RecordClass', $className);
            if ($collection) {
                return $collection;
            }
        }

        return null;
    }

    private function buildDocumentData($record, $collection): array
    {
        $fieldsArray = $collection->FieldsArray();

        $restoreSourceParams = false;
        $originalSourceParams = null;
        if ($this->isVersionedRecord()
            && $record->hasMethod('getSourceQueryParams')
            && $record->hasMethod('setSourceQueryParams')
        ) {
            $originalSourceParams = $record->getSourceQueryParams() ?: [];
            $sanitisedSourceParams = $originalSourceParams;
            unset(
                $sanitisedSourceParams['Versioned.mode'],
                $sanitisedSourceParams['Versioned.stage'],
                $sanitisedSourceParams['Versioned.date']
            );
            $record->setSourceQueryParams($sanitisedSourceParams);
            $restoreSourceParams = true;
        }

        try {
            if ($record->hasMethod('getTypesenseDocument')) {
                return $record->getTypesenseDocument($fieldsArray);
            }

            return $collection->getTypesenseDocument($record, $fieldsArray);
        } finally {
            if ($restoreSourceParams) {
                $record->setSourceQueryParams($originalSourceParams);
            }
        }
    }

    public function onAfterWrite()
    {
        try {
            if ($this->isValidTypesenseClass($this->getOwnerClassName()) && $this->shouldSyncOnWrite()) {
                $client = Typesense::client();
                $collection = $this->getTypesenseCollection();
                if ($collection && $collection->checkExistence()) {
                    $record = $this->owner;
                    $data = $this->buildDocumentData($record, $collection);
                    $client->collections[$collection->Name]->documents->upsert($data);
                }
            }
        } catch (RequestMalformed $e) {
            Injector::inst()->get(LoggerInterface::class)->info($e->getMessage());
        }
    }

    public function onBeforeDelete()
    {
        try {
            if ($this->isValidTypesenseClass($this->getOwnerClassName()) && $this->shouldSyncDelete()) {
                $client = Typesense::client();
                $collection = $this->getTypesenseCollection();
                if ($collection && $collection->checkExistence() && $this->owner->ID) {
                    $client->collections[$collection->Name]->documents[(string)$this->owner->ID]->delete();
                }
            }
        } catch (ObjectNotFound $e) {
            Injector::inst()->get(LoggerInterface::class)->info($e->getMessage());
        }
    }
}
