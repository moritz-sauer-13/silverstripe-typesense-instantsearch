<?php

namespace MoritzSauer\Instantsearch\Tasks;

use ElliotSawyer\SilverstripeTypesense\Collection;
use ElliotSawyer\SilverstripeTypesense\Field;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

class TypesenseAclSetupTask extends BuildTask
{
    protected string $title = 'Typesense ACL setup task';
    protected static string $description = 'Ensures ACL fields exist on all Typesense collections.';
    private static string $segment = 'TypesenseAclSetupTask';

    protected function execute(InputInterface $input, PolyOutput $output): int
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
            $output->writeln(sprintf('Checking collection %s', $collection->Name));

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
                    $output->writeln(sprintf('  Added field %s', $definition['name']));
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
                    $output->writeln(sprintf('  Updated field %s', $definition['name']));
                }
            }

            // Typesense cannot use optional fields as default_sorting_field.
            if ($collection->DefaultSortingField) {
                $defaultSortField = $collection->Fields()->find('name', $collection->DefaultSortingField);
                if (!$defaultSortField || (bool)$defaultSortField->optional) {
                    $output->writeln(sprintf(
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
                $output->writeln('  Synced collection schema on Typesense server');
            } catch (\Throwable $exception) {
                $hasErrors = true;
                $output->writeln(sprintf(
                    '  Failed syncing schema: %s',
                    $exception->getMessage()
                ));
            }
        }

        $output->writeln('Typesense ACL field setup complete.');

        return $hasErrors ? Command::FAILURE : Command::SUCCESS;
    }
}
