<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\DevImage;

use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tooling\DevImage\Domain\DevImageRun;
use Cbox\Cms\Tooling\DevImage\Domain\NodeModulesStamp;
use Cbox\Cms\Tooling\DevImage\Domain\VolumeKind;
use Symfony\Component\Process\Process;

/*
 * tools/bin/dev-image-entry.php is the first program of every run in the dev image. It brings the
 * checkout's Linux node_modules volume to package-lock.json with npm ci when the lock file changed
 * since the last install, and then replaces itself with the command. It runs here in a scratch
 * checkout with a fake npm on the PATH.
 */

/**
 * @param  list<string>  $command
 * @return array{exitCode: int|null, output: string, errors: string, npm: list<string>}
 */
function runDevImageEntry(string $checkout, array $command, int $npmExit = 0, ?string $hostBootstrapCache = null): array
{
    $bin = ScratchDirectory::make('cbox-cms-fake-npm-');
    $log = $bin.'/npm.log';
    ScratchDirectory::write($bin.'/npm', <<<'SH'
        #!/bin/sh
        printf '%s PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=%s\n' "$*" "${PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD:-}" >> "$FAKE_NPM_LOG"
        rm -rf node_modules/* node_modules/.[!.]* 2>/dev/null
        mkdir -p node_modules
        exit "$FAKE_NPM_EXIT"
        SH);
    chmod($bin.'/npm', 0o755);

    $process = new Process(
        [PHP_BINARY, Phpstan::root().'/tools/bin/dev-image-entry.php', ...$command],
        $checkout,
        ['PATH' => $bin.':'.getenv('PATH'), 'FAKE_NPM_LOG' => $log, 'FAKE_NPM_EXIT' => (string) $npmExit, DevImageRun::HOST_BOOTSTRAP_CACHE_VARIABLE => $hostBootstrapCache ?? false],
    );
    $process->setTimeout(60);
    $process->run();

    return [
        'exitCode' => $process->getExitCode(),
        'output' => $process->getOutput(),
        'errors' => $process->getErrorOutput(),
        'npm' => is_file($log) ? file($log, FILE_IGNORE_NEW_LINES) ?: [] : [],
    ];
}

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

it('installs node_modules with npm ci on the first run, stamps it, and then runs the command in the checkout', function (): void {
    $checkout = (string) realpath(ScratchDirectory::make('cbox-cms-entry-checkout-'));
    ScratchDirectory::write($checkout.'/package-lock.json', '{"lockfileVersion":3}');

    $first = runDevImageEntry($checkout, ['sh', '-c', 'echo "ran in $PWD with $0"', 'probe']);
    $second = runDevImageEntry($checkout, ['sh', '-c', 'exit 5']);

    expect($first['exitCode'])->toBe(0)
        ->and($first['npm'])->toBe(['ci --no-audit --no-fund --no-update-notifier PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1'])
        ->and($first['output'])->toContain("ran in {$checkout} with probe")
        ->and(file_get_contents($checkout.'/'.NodeModulesStamp::FILE))->toBe(NodeModulesStamp::of('{"lockfileVersion":3}'))
        ->and($second['npm'])->toBe([])
        ->and($second['exitCode'])->toBe(5);
});

it('installs again when package-lock.json changed since the last install', function (): void {
    $checkout = (string) realpath(ScratchDirectory::make('cbox-cms-entry-checkout-'));
    ScratchDirectory::write($checkout.'/package-lock.json', '{"lockfileVersion":3}');
    runDevImageEntry($checkout, ['true']);
    ScratchDirectory::write($checkout.'/package-lock.json', '{"lockfileVersion":3,"packages":{}}');

    $run = runDevImageEntry($checkout, ['true']);

    expect($run['exitCode'])->toBe(0)
        ->and($run['npm'])->toHaveCount(1)
        ->and($run['errors'])->toContain('package-lock.json changed since the last install')
        ->and(file_get_contents($checkout.'/'.NodeModulesStamp::FILE))->toBe(NodeModulesStamp::of('{"lockfileVersion":3,"packages":{}}'));
});

it('exits with npm ci\'s code, leaves no stamp and runs no command when the install fails', function (): void {
    $checkout = (string) realpath(ScratchDirectory::make('cbox-cms-entry-checkout-'));
    ScratchDirectory::write($checkout.'/package-lock.json', '{"lockfileVersion":3}');

    $run = runDevImageEntry($checkout, ['sh', '-c', 'echo should-not-run'], npmExit: 7);

    expect($run['exitCode'])->toBe(7)
        ->and($run['output'])->not->toContain('should-not-run')
        ->and($run['errors'])->toContain('npm ci failed with exit code 7')
        ->and(is_file($checkout.'/'.NodeModulesStamp::FILE))->toBeFalse();
});

it('exits 2 without a command and 127 when the command cannot start', function (): void {
    $checkout = (string) realpath(ScratchDirectory::make('cbox-cms-entry-checkout-'));

    $usage = runDevImageEntry($checkout, []);
    $missing = runDevImageEntry($checkout, ['./no-such-program']);

    expect($usage['exitCode'])->toBe(2)
        ->and($usage['errors'])->toContain('Usage: php tools/bin/dev-image-entry.php')
        ->and($missing['exitCode'])->toBe(127)
        ->and($missing['errors'])->toContain('cannot start ./no-such-program')
        ->and($missing['npm'])->toBe([]);
});

it('mirrors the host\'s bootstrap cache into the checkout\'s volume before the command, removing what the host does not have', function (): void {
    $checkout = (string) realpath(ScratchDirectory::make('cbox-cms-entry-checkout-'));
    $host = (string) realpath(ScratchDirectory::make('cbox-cms-entry-host-cache-'));
    $volume = $checkout.'/'.VolumeKind::BootstrapCache->directory();
    ScratchDirectory::write($host.'/services.php', "<?php return ['host'];\n");
    ScratchDirectory::write($host.'/cms/commands.php', "<?php return [];\n");
    ScratchDirectory::write($volume.'/services.php', "<?php return ['stale'];\n");
    ScratchDirectory::write($volume.'/cms/slots.php', "<?php return [];\n");
    ScratchDirectory::write($volume.'/leftover.php', "<?php\n");

    $run = runDevImageEntry($checkout, ['sh', '-c', 'cat '.$volume.'/services.php'], hostBootstrapCache: $host);

    expect($run['exitCode'])->toBe(0)
        ->and($run['output'])->toContain("['host']")
        ->and(file_get_contents($volume.'/cms/commands.php'))->toBe("<?php return [];\n")
        ->and(file_exists($volume.'/cms/slots.php'))->toBeFalse()
        ->and(file_exists($volume.'/leftover.php'))->toBeFalse()
        ->and(file_get_contents($host.'/services.php'))->toBe("<?php return ['host'];\n");
});
