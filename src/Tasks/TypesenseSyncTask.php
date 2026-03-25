<?php
/**
 * Silverstripe Typesense module
 * @license LGPL3 With Attribution
 * @copyright Copyright (C) 2024 Elliot Sawyer
 */

namespace ElliotSawyer\SilverstripeTypesense;

use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

class TypesenseSyncTask extends BuildTask
{
    protected static string $commandName = 'TypesenseSyncTask';

    public function getTitle(): string
    {
        return _t(TypesenseSyncTask::class . '.TITLE', 'Typesense sync task');
    }

    public static function getDescription(): string
    {
        return _t(TypesenseSyncTask::class . '.DESCRIPTION', 'Creates and indexes your Typesense collections');
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $copyright = (new Typesense())->CopyrightStatement();
        $output->writeln($copyright);
        $this->extend('onBeforeBuildAllCollections');
        $collections = $this->findOrMakeAllCollections();
        $this->extend('onAfterBuildAllCollections', $collections);
        if (!$collections) {
            $output->writeln("No collections to build");
            return Command::INVALID;
        }

        $this->extend('onBeforeImportDocuments');
        foreach ($collections as $collection) {
            if (!$collection->checkExistence()) {
                $output->writeln($collection->syncWithTypesenseServer());
            }
            $collection->import();
        }
        $this->extend('onAfterImportDocuments', $collections);
        $this->extend('onEndOfSyncTask');
        return Command::SUCCESS;
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
