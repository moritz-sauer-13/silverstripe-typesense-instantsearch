<?php

namespace MoritzSauer\Instantsearch\Tasks;

use ElliotSawyer\SilverstripeTypesense\Collection;
use ElliotSawyer\SilverstripeTypesense\Field;
use SilverStripe\Dev\BuildTask;
use Throwable;

class TypesenseAclSetupTask extends BuildTask
{
    protected $title = 'Typesense ACL setup task';
    protected $description = 'Ensures ACL fields exist on all Typesense collections.';
    private static $segment = 'TypesenseAclSetupTask';

    public function run($request)
    {
        $hasErrors = false;

        $baseDefinitions = [
            [
                'name' => 'AccessibleTo',
                'type' => 'string[]',
                'facet' => 0,
                'optional' => 1,
                'index' => 1,
                'sort' => 0,
                'store' => 1,
                'infix' => 0,
            ],
            [
                'name' => 'SearchVisible',
                'type' => 'bool',
                'facet' => 0,
                'optional' => 1,
                'index' => 1,
                'sort' => 0,
                'store' => 1,
                'infix' => 0,
            ],
        ];

        foreach (Collection::get() as $collection) {
            $this->writeLine(sprintf('Checking collection %s', $collection->Name));

            $definitions = $baseDefinitions;
            if ((string)$collection->RecordClass === 'DNADesign\\Elemental\\Models\\BaseElement') {
                $definitions[] = [
                    'name' => 'Link',
                    'type' => 'string',
                    'facet' => 0,
                    'optional' => 1,
                    'index' => 0,
                    'sort' => 0,
                    'store' => 1,
                    'infix' => 0,
                ];
            }

            foreach ($definitions as $definition) {
                $field = $collection->Fields()->filter('name', $definition['name'])->first();
                if (!$field) {
                    $field = Field::create($definition + ['CollectionID' => $collection->ID]);
                    $field->write();
                    $this->writeLine(sprintf('  Added field %s', $definition['name']));
                    continue;
                }

                $hasChanges = false;
                foreach ($definition as $key => $value) {
                    if ((string)$field->{$key} !== (string)$value) {
                        $field->{$key} = $value;
                        $hasChanges = true;
                    }
                }

                if ($hasChanges) {
                    $field->write();
                    $this->writeLine(sprintf('  Updated field %s', $definition['name']));
                }
            }

            // Typesense cannot use optional fields as default_sorting_field.
            if ($collection->DefaultSortingField) {
                $defaultSortField = $collection->Fields()->find('name', $collection->DefaultSortingField);
                if (!$defaultSortField || (bool)$defaultSortField->optional) {
                    $this->writeLine(sprintf(
                        '  Cleared invalid default sorting field %s',
                        $collection->DefaultSortingField
                    ));
                    $collection->DefaultSortingField = null;
                    $collection->write();
                }
            }

            // Ensure remote Typesense schema contains the ACL fields.
            try {
                $collection->syncWithTypesenseServer();
                $this->writeLine('  Synced collection schema on Typesense server');
            } catch (Throwable $exception) {
                $hasErrors = true;
                $this->writeLine(sprintf(
                    '  Failed syncing schema: %s',
                    $exception->getMessage()
                ));
            }
        }

        $this->writeLine('Typesense ACL field setup complete.');
        if ($hasErrors) {
            $this->writeLine('Completed with one or more errors.');
        }
    }

    private function writeLine($message)
    {
        echo $message . PHP_EOL;
    }
}
