<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor;

use Cbox\Cms\Core\Doctor\Adapter\IniPhpSettingsProbe;
use Symfony\Component\Process\Process;

/*
 * The probe behind php.allow_url_fopen reads the setting of the process it runs in. A running
 * process cannot change allow_url_fopen, so each case starts PHP with the setting given by -d, in
 * each spelling php.ini accepts.
 */

it('reads allow_url_fopen as PHP was started with it', function (string $setting, bool $expected): void {
    $script = sprintf(
        'require %s; echo json_encode(new %s()->allowUrlFopen());',
        var_export(dirname(__DIR__, 4).'/vendor/autoload.php', true),
        IniPhpSettingsProbe::class,
    );
    $process = new Process([PHP_BINARY, '-n', '-d', 'allow_url_fopen='.$setting, '-r', $script]);
    $process->mustRun();

    expect($process->getOutput())->toBe($expected ? 'true' : 'false');
})->with([
    'On' => ['On', true],
    '1' => ['1', true],
    'Off' => ['Off', false],
    '0' => ['0', false],
    'empty' => ['', false],
]);
