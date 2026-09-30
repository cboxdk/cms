<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\FieldTypes;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What an addon gives cms:generate for the field types it contributes (PRD 13.1, 13.3, 11.12). The
 * addon's manifest lists the field types in SchemaContributions and names the class that implements
 * this interface; cms:build writes both to schema.php, and cms:generate makes the class through the
 * container and registers the field types it returns, only in the addon's namespace.
 *
 * It returns exactly the field types the manifest lists, each once: cms:generate refuses a
 * contributor that returns another name, or leaves one out, with generate_invalid_config.
 */
#[Experimental]
interface FieldTypeContributor
{
    /**
     * @return list<FieldTypeContribution>
     */
    public function fieldTypes(): array;
}
