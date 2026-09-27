<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Ci;

use Cbox\Cms\Core\Doctor\Adapter\IniPhpSettingsProbe;
use Cbox\Cms\Core\Doctor\Domain\Checks\AllowUrlFopenCheck;
use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\CiFiles;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Symfony\Component\Process\Process;

/*
 * The php service of compose.yaml reads the PHP settings of the runtime contract from
 * docker/php/conf.d/cms.ini (PRD 4.2, GUARDRAILS 3), so cms:doctor passes php.allow_url_fopen in
 * the development environment without a -d flag. Each case starts PHP with an extra scan
 * directory in PHP_INI_SCAN_DIR, which a leading colon appends to the directory PHP was built with,
 * and reads the setting and the doctor check in that process. ComposeServicesTest holds the mount.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

/**
 * A conf.d that turns allow_url_fopen on, as the image's 99-cbox.ini and Herd's php.ini do.
 */
function urlFopenOnDirectory(): string
{
    $directory = ScratchDirectory::make('cbox-cms-php-ini-');
    ScratchDirectory::write($directory.'/99-cbox.ini', "allow_url_fopen = On\n");

    return $directory;
}

/**
 * Where the php service of compose.yaml mounts docker/php/conf.d/cms.ini.
 */
function mountedDropIn(): string
{
    $volumes = CiFiles::at(CiFiles::yaml(CiFiles::COMPOSE), 'services', 'php', 'volumes');
    $prefix = './docker/php/conf.d/cms.ini:';
    $mounts = array_values(array_filter(
        is_array($volumes) ? $volumes : [],
        static fn (mixed $volume): bool => is_string($volume) && str_starts_with($volume, $prefix),
    ));

    expect($mounts)->toHaveCount(1);

    return is_string($mounts[0] ?? null) ? explode(':', $mounts[0])[1] : '';
}

/**
 * ini_get('allow_url_fopen') and the status of the php.allow_url_fopen check in a PHP process
 * started with these scan directories.
 *
 * @param  list<string>  $directories
 * @return array{ini: bool, check: string}
 */
function withScanDirectories(array $directories): array
{
    $script = sprintf(
        'require %s; echo json_encode(["ini" => (bool) ini_get("allow_url_fopen"), "check" => new %s(new %s())->run()->status->value]);',
        var_export(Phpstan::root().'/vendor/autoload.php', true),
        AllowUrlFopenCheck::class,
        IniPhpSettingsProbe::class,
    );
    $process = new Process([PHP_BINARY, '-r', $script], Phpstan::root(), ['PHP_INI_SCAN_DIR' => ':'.implode(':', $directories)]);
    $process->mustRun();

    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    $ini = is_array($result) ? $result['ini'] ?? null : null;
    $check = is_array($result) ? $result['check'] ?? null : null;

    expect($ini)->toBeBool()
        ->and($check)->toBeString();

    return ['ini' => is_bool($ini) && $ini, 'check' => is_string($check) ? $check : ''];
}

it('turns allow_url_fopen off, so the php.allow_url_fopen check passes', function (): void {
    expect(withScanDirectories([Phpstan::root().'/docker/php/conf.d']))->toBe(['ini' => false, 'check' => 'pass']);
});

it('turns allow_url_fopen off after a scan directory that turned it on', function (): void {
    $on = urlFopenOnDirectory();

    expect(withScanDirectories([$on]))->toBe(['ini' => true, 'check' => 'fail'])
        ->and(withScanDirectories([$on, Phpstan::root().'/docker/php/conf.d']))->toBe(['ini' => false, 'check' => 'pass']);
});

it('wins over 99-cbox.ini in one conf.d under the name compose.yaml mounts it as', function (): void {
    $confD = urlFopenOnDirectory();
    $mounted = basename(mountedDropIn());
    $contents = file_get_contents(Phpstan::root().'/docker/php/conf.d/cms.ini');

    expect($contents)->toBeString();

    ScratchDirectory::write($confD.'/'.$mounted, is_string($contents) ? $contents : '');

    expect(withScanDirectories([$confD]))->toBe(['ini' => false, 'check' => 'pass']);
});
