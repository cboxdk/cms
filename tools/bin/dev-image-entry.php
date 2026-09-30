<?php

declare(strict_types=1);

/*
 * The first program of every run in the dev image (DevImageRun), in the container, in the root of
 * the checkout, which DevImageRun makes the working directory:
 *
 *   php tools/bin/dev-image-entry.php <command> [<argument>...]
 *
 * First, when CMS_HOST_BOOTSTRAP_CACHE names a directory, the host's bootstrap cache of the
 * Testbench application mounted there, it mirrors it into the checkout's bootstrap cache volume
 * (VolumeKind::BootstrapCache, DirectoryMirror), so the run starts from the manifests and the
 * registry cache the host has.
 *
 * node_modules in the container is the checkout's own Linux volume (VolumeKind::NodeModules).
 * When the SHA-256 of package-lock.json is not the stamp the last install left in it (NodeModulesStamp),
 * it runs `npm ci` there first, without Playwright's browser download, because the image has
 * Playwright's Chromium in PLAYWRIGHT_BROWSERS_PATH, and then writes the stamp. Then it replaces
 * itself with the command, which keeps this process's environment, standard streams and signals.
 *
 * Exits 2 without a command, 1 when the bootstrap cache cannot be mirrored, with npm ci's exit
 * code when the install fails, and 127 when the command cannot be started.
 */

use Cbox\Cms\Tooling\Check\Boundary\CommandLine;
use Cbox\Cms\Tooling\DevImage\Boundary\DirectoryMirror;
use Cbox\Cms\Tooling\DevImage\Domain\DevImageRun;
use Cbox\Cms\Tooling\DevImage\Domain\NodeModulesStamp;
use Cbox\Cms\Tooling\DevImage\Domain\VolumeKind;

require dirname(__DIR__, 2).'/vendor/autoload.php';

// The checkout: DevImageRun makes it the working directory.
$root = (string) getcwd();

$arguments = CommandLine::arguments();

if ($arguments === []) {
    fwrite(STDERR, "Usage: php tools/bin/dev-image-entry.php <command> [<argument>...]\n");
    exit(2);
}

$hostBootstrapCache = getenv(DevImageRun::HOST_BOOTSTRAP_CACHE_VARIABLE);

if (is_string($hostBootstrapCache) && is_dir($hostBootstrapCache)) {
    $bootstrapCache = $root.'/'.VolumeKind::BootstrapCache->directory();

    try {
        if (! is_dir($bootstrapCache) && ! mkdir($bootstrapCache, 0o755, true) && ! is_dir($bootstrapCache)) {
            throw new UnexpectedValueException("Cannot create {$bootstrapCache}.");
        }

        DirectoryMirror::mirror($hostBootstrapCache, $bootstrapCache);
    } catch (UnexpectedValueException $exception) {
        fwrite(STDERR, 'dev image: cannot mirror the host\'s bootstrap cache: '.$exception->getMessage()."\n");
        exit(1);
    }
}

$lock = is_file($root.'/package-lock.json') ? file_get_contents($root.'/package-lock.json') : false;
$stampFile = $root.'/'.NodeModulesStamp::FILE;

if (is_string($lock) && ! NodeModulesStamp::current($lock, is_file($stampFile) ? (string) file_get_contents($stampFile) : null)) {
    fwrite(STDERR, "dev image: installing node_modules for Linux with npm ci, because package-lock.json changed since the last install in this checkout's volume.\n");
    $install = proc_open(
        ['npm', 'ci', '--no-audit', '--no-fund', '--no-update-notifier'],
        [STDIN, STDOUT, STDERR],
        $pipes,
        $root,
        [...getenv(), 'PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD' => '1'],
    );
    $exitCode = $install === false ? 1 : proc_close($install);

    if ($exitCode !== 0) {
        fwrite(STDERR, "dev image: npm ci failed with exit code {$exitCode}.\n");
        exit($exitCode);
    }

    file_put_contents($stampFile, NodeModulesStamp::of($lock));
}

$program = $arguments[0];

if (! str_contains($program, '/')) {
    $path = getenv('PATH');

    foreach (explode(':', is_string($path) ? $path : '') as $directory) {
        if ($directory !== '' && is_executable($directory.'/'.$program)) {
            $program = $directory.'/'.$program;

            break;
        }
    }
}

pcntl_exec($program, array_slice($arguments, 1));

fwrite(STDERR, "dev image: cannot start {$arguments[0]}: ".pcntl_strerror(pcntl_get_last_error())."\n");
exit(127);
