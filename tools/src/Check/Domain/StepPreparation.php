<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

/**
 * Prepares the command of a step whose precheck decided it runs, from what the steps before it
 * found: it may write a file below the checked directory's git-ignored .cache/ and gives the
 * variables, on top of the step's own, that hand it to the command. A precheck that implements it
 * is asked after passedWithout() answered null, just before the command runs.
 */
interface StepPreparation
{
    /**
     * @return array<string, string>
     */
    public function prepare(string $directory): array;
}
