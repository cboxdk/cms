<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;

/**
 * Reads the fixture schema that cms:generate generates from.
 */
#[Internal]
interface SchemaSource
{
    /**
     * @param  string  $path  the absolute path of the schema file
     *
     * @throws GenerationFailed
     */
    public function load(string $path): FixtureSchema;
}
