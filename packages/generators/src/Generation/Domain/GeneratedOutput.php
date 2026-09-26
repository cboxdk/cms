<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationResult;
use Cbox\Cms\Generators\Generation\Domain\Dto\WriteReport;

/**
 * Where the generated code is written.
 */
#[Internal]
interface GeneratedOutput
{
    /**
     * Writes every file whose contents differ, leaves identical files untouched, and removes the
     * files in the owned directories that the result does not contain.
     *
     * @param  string  $root  the absolute directory the result's paths are relative to
     *
     * @throws GenerationFailed with GenerateErrorCode::OutputUnwritable
     */
    public function write(string $root, GenerationResult $result): WriteReport;
}
