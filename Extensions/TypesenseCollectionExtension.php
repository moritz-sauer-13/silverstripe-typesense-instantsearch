<?php

namespace MoritzSauer\Instantsearch\Extensions;

use SilverStripe\Core\Environment;
use SilverStripe\Core\Extension;
use SilverStripe\Dev\Debug;

class TypesenseCollectionExtension extends Extension
{

    public function onBeforeWrite()
    {
        if (Environment::getEnv('TYPESENSE_COLLECTION_PREFIX') !== null) {
            if (!str_starts_with($this->owner->Name, Environment::getEnv('TYPESENSE_COLLECTION_PREFIX'))) {
                $this->owner->Name = Environment::getEnv('TYPESENSE_COLLECTION_PREFIX') . $this->owner->Name;
            }
        }
    }
}
