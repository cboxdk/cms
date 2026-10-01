<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling;

use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tooling\Check\Boundary\ComposerCommand;
use Symfony\Component\Process\Process;

/*
 * composer.json passes `composer validate --strict` (M1 exit criterion E12): no warning, such as an
 * exact version constraint on a package that follows semantic versioning, and a composer.lock whose
 * content hash matches composer.json. A tested version stays fixed by the lock, not by the constraint.
 */

it('passes composer validate in strict mode', function (): void {
    $process = new Process(
        [...ComposerCommand::resolve(PHP_BINARY), 'validate', '--strict', '--no-ansi', '--no-interaction'],
        Phpstan::root(),
        null,
        null,
        120,
    );
    $process->run();

    $output = $process->getErrorOutput().$process->getOutput();

    expect($output)->not->toContain('warning')
        ->and($process->getExitCode())->toBe(0, $output);
});
