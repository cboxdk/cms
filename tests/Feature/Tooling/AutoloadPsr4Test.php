<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling;

use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tooling\Check\Boundary\ComposerCommand;
use Symfony\Component\Process\Process;

/*
 * Every class-like under an autoload root sits in the file PSR-4 names for it, tests included,
 * so `composer dump-autoload --optimize --strict-psr` exits 0. A helper class declared inside a
 * *Test.php file breaks it: it goes in a file of its own, named after the class. A fixture that
 * must stay unloadable is listed under exclude-from-classmap.
 */

it('dumps an optimized autoloader with no PSR-4 violation', function (): void {
    $process = new Process(
        [...ComposerCommand::resolve(PHP_BINARY), 'dump-autoload', '--optimize', '--strict-psr', '--dry-run', '--no-scripts', '--no-ansi', '--no-interaction'],
        Phpstan::root(),
        null,
        null,
        120,
    );
    $process->run();

    $output = $process->getErrorOutput().$process->getOutput();

    expect($output)->not->toContain('does not comply with psr-4')
        ->and($process->getExitCode())->toBe(0, $output);
});
