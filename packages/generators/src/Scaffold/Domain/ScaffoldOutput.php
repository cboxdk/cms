<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Scaffold\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Scaffold\Domain\Dto\AddonPackage;
use Cbox\Cms\Generators\Scaffold\Domain\Dto\ScaffoldReport;
use Cbox\Cms\Generators\Scaffold\Domain\Dto\ScaffoldResult;

/**
 * Where a scaffold reads and writes an addon's package: the port of ScaffoldAddonUi and
 * ScaffoldContribution; the generators bind it to Adapter\FilesystemScaffoldOutput.
 */
#[Internal]
interface ScaffoldOutput
{
    /**
     * The package as its composer.json describes it.
     *
     * @throws GenerationFailed with generate_invalid_config when the root has no readable composer.json with a name and a PSR-4 namespace
     */
    public function package(string $root): AddonPackage;

    /**
     * The contents of a file below the root, or null when there is none.
     */
    public function read(string $root, string $path): ?string;

    /**
     * Writes the result's files where none exists and its updates whatever exists.
     *
     * @throws GenerationFailed with generate_output_unwritable
     */
    public function write(string $root, ScaffoldResult $result): ScaffoldReport;
}
