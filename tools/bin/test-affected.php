<?php

declare(strict_types=1);

/*
 * `composer test:affected [-- <pest arguments>]`: fast feedback while working, not a gate. It runs
 * in the php-baseimages dev image (DevImage); started on the host, it runs itself again there for
 * this checkout, as `composer check` does (DockerDevImage).
 *
 * Pest's test impact analysis (`--tia`) records, per test, the files and tables it touches through
 * PCOV, in the graph below ~/.pest/tia, which the host's ~/.pest shares with every run. The first
 * run records the graph; later runs rerun only the tests that depend on what changed and replay the
 * results of the others. The analysis takes Pest files only and stops at the first PHPUnit test
 * class (TestFileKind), so the run is two runs of Pest over the suites of gate 5
 * (LocalProfile::SUITES), each with --parallel and the arguments given:
 *
 *   1. the Pest files, with --tia and PCOV on (tools/tia/pcov.ini), in .cache/test-affected/pest.xml,
 *      phpunit.xml without the PHPUnit test classes;
 *   2. the PHPUnit test classes, such as the contract suites' one class per implementation, all of
 *      them every time, in .cache/test-affected/classes.xml, phpunit.xml without the Pest files.
 *
 * The Browser suite runs with `composer image:run -- vendor/bin/pest --testsuite=Browser`, and the
 * Mutation suite, whose tests start Pest runs of their own, with --testsuite=Mutation. An argument
 * that selects tests, such as --filter, --testsuite or a path, makes Pest run the
 * selection without the analysis. Exits 0 when both runs passed, else the exit code of the first
 * that failed; 1 when the test files cannot be listed.
 */

use Cbox\Cms\Tooling\Affected\Boundary\PhpunitConfiguration;
use Cbox\Cms\Tooling\Affected\Domain\TestFileKind;
use Cbox\Cms\Tooling\Check\Boundary\CommandLine;
use Cbox\Cms\Tooling\Check\Domain\LocalProfile;
use Cbox\Cms\Tooling\DevImage\Adapter\DockerDevImage;
use Cbox\Cms\Tooling\DevImage\Domain\DevImage;
use Symfony\Component\Process\Process;

$root = (string) realpath(dirname(__DIR__, 2));

require $root.'/vendor/autoload.php';

$arguments = CommandLine::arguments();

if (! DevImage::runsIn(getenv(DevImage::TIER_VARIABLE))) {
    exit(new DockerDevImage()->run($root, ['php', 'tools/bin/test-affected.php', ...$arguments]));
}

$list = new Process(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z', '--', '*Test.php'], $root, null, null, 120);
$list->run();

if (! $list->isSuccessful()) {
    fwrite(STDERR, 'Cannot list the test files with git ls-files: '.trim($list->getErrorOutput())."\n");
    exit(1);
}

$files = ['classes' => [], 'pest' => []];

foreach (array_filter(explode("\0", $list->getOutput())) as $file) {
    $path = $root.'/'.$file;

    if (is_file($path)) {
        $files[TestFileKind::of($path, (string) file_get_contents($path)) === TestFileKind::PhpunitClass ? 'classes' : 'pest'][] = $path;
    }
}

$directory = $root.'/.cache/test-affected';

if (! is_dir($directory) && ! mkdir($directory, 0o755, true) && ! is_dir($directory)) {
    fwrite(STDERR, "Cannot create {$directory}.\n");
    exit(1);
}

$phpunit = (string) file_get_contents($root.'/phpunit.xml');
$runs = [
    [
        'title' => sprintf('Pest files (%d), with test impact analysis', count($files['pest'])),
        'configuration' => $directory.'/pest.xml',
        'excluded' => $files['classes'],
        'flags' => ['--parallel', '--tia'],
        'environment' => ['PHP_INI_SCAN_DIR' => ':'.$root.'/tools/tia'],
    ],
    [
        'title' => sprintf('PHPUnit test classes (%d), which the analysis does not take: every one', count($files['classes'])),
        'configuration' => $directory.'/classes.xml',
        'excluded' => $files['pest'],
        'flags' => ['--parallel'],
        'environment' => [],
    ],
];
$exitCode = 0;

foreach ($runs as $run) {
    file_put_contents($run['configuration'], PhpunitConfiguration::without($phpunit, $root, LocalProfile::SUITES, $run['excluded']));
    fwrite(STDOUT, "\ncomposer test:affected: {$run['title']}\n");

    $process = proc_open(
        [PHP_BINARY, 'vendor/bin/pest', '--configuration='.$run['configuration'], ...$run['flags'], ...$arguments],
        [STDIN, STDOUT, STDERR],
        $pipes,
        $root,
        [...getenv(), ...$run['environment']],
    );
    $code = $process === false ? 1 : proc_close($process);
    $exitCode = $exitCode === 0 ? $code : $exitCode;
}

exit($exitCode);
