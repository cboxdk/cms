<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Phpstan;

use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\PhpstanAnalysis;
use Illuminate\Filesystem\Filesystem;

/*
 * A session is issued only with a decision that only the policy check can construct (PRD 5.16,
 * "Loginpolitik"): LoginDecision's constructor is private, so PHPStan level 10, with the
 * repository's configuration, refuses a login path that makes its own decision and accepts one
 * that asks CheckLoginPolicy. The fixture is analysed from a copy with the .php extension in a
 * scratch directory.
 */

function analyseLoginPaths(): PhpstanAnalysis
{
    $directory = sys_get_temp_dir().'/cms-identity-phpstan-'.bin2hex(random_bytes(6));
    mkdir($directory, 0o775, true);
    copy(__DIR__.'/Fixtures/LoginPaths.php.inc', $directory.'/LoginPaths.php');

    try {
        return Phpstan::analyse($directory.'/LoginPaths.php');
    } finally {
        new Filesystem()->deleteDirectory($directory);
    }
}

it('refuses new LoginDecision outside the policy check, and accepts the path that asks the policy', function (): void {
    $analysis = analyseLoginPaths();

    expect($analysis->identifiers)->toBe(['new.privateConstructor']);
});
