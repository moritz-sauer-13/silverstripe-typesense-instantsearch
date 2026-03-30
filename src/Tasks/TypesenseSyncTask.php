<?php
/**
 * Silverstripe Typesense module
 * @license LGPL3 With Attribution
 * @copyright Copyright (C) 2024 Elliot Sawyer
 */

namespace ElliotSawyer\SilverstripeTypesense;

use SilverStripe\Dev\BuildTask;

class TypesenseSyncTask extends BuildTask
{
    protected $title = 'Typesense sync task';
    protected $description = 'Creates and indexes your Typesense collections';
    private static $segment = 'TypesenseSyncTask';

    public function run($request)
    {
        $copyright = (new Typesense())->CopyrightStatement();
        $this->writeLine($copyright);
        $this->extend('onBeforeBuildAllCollections');
        $collections = $this->findOrMakeAllCollections();
        $this->extend('onAfterBuildAllCollections', $collections);
        if (!$collections->exists()) {
            $this->writeLine('No collections to build');
            return;
        }

        $this->extend('onBeforeImportDocuments');
        foreach ($collections as $collection) {
            if (!$collection->checkExistence()) {
                $this->writeLine($collection->syncWithTypesenseServer());
            }
            $collection->import();
        }
        $this->extend('onAfterImportDocuments', $collections);
        $this->extend('onEndOfSyncTask');
    }

    private function writeLine($message)
    {
        echo $message . PHP_EOL;
    }

    private function findOrMakeAllCollections()
    {
        $ymlIndexes = Typesense::config()->get('collections') ?? [];
        foreach ($ymlIndexes as $recordClass => $collection) {
            $collectionName = $collection['name'] ?? null;
            if (!$collectionName) {
                continue;
            }

            Collection::find_or_make($collectionName, $recordClass, $collection);
        }
        return Collection::get()
            ->sort('Sort ASC')
            ->filter([
                'Enabled' => true
            ]);
    }
}
