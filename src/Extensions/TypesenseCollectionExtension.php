<?php

namespace MoritzSauer\Instantsearch\Extensions;

use SilverStripe\Core\Environment;
use SilverStripe\Core\Extension;

class TypesenseCollectionExtension extends Extension
{

    public function onBeforeWrite()
    {
        $prefix = Environment::getEnv('TYPESENSE_COLLECTION_PREFIX');
        if ($prefix !== null && $prefix !== '') {
            if (strpos((string)$this->owner->Name, (string)$prefix) !== 0) {
                $this->owner->Name = $prefix . $this->owner->Name;
            }
        }
    }
}
