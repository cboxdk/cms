<?php

declare(strict_types=1);

/*
 * The first step of `composer dev:prepare`: gives the workbench's settings file an application key
 * (WorkbenchEnvironment), in the root of the checkout, which is the working directory:
 *
 *   php tools/bin/workbench-env.php
 *
 * Without workbench/.env it writes the file from workbench/.env.example with a new key; with a file
 * whose APP_KEY is missing or empty it writes a new key into it; a file with a key stays as it is.
 * Then it copies workbench/.env to Testbench's Laravel application when the two differ, because
 * Testbench copies the file there only when the application has none. A second run changes nothing.
 *
 * Exits 0, and 1 when workbench/.env.example is missing or a file cannot be written.
 */

use Cbox\Cms\Tooling\Workbench\Domain\WorkbenchEnvironment;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$root = (string) getcwd();
$file = $root.'/'.WorkbenchEnvironment::FILE;
$example = $root.'/'.WorkbenchEnvironment::EXAMPLE;

if (! is_file($example)) {
    fwrite(STDERR, 'workbench env: '.WorkbenchEnvironment::EXAMPLE." is missing, so there is nothing to make the workbench's settings from. Run this from the root of the checkout.\n");
    exit(1);
}

$current = is_file($file) ? (string) file_get_contents($file) : null;
$settings = WorkbenchEnvironment::withKey((string) file_get_contents($example), $current, WorkbenchEnvironment::newKey(random_bytes(32)));

if ($settings === null) {
    fwrite(STDOUT, 'workbench env: '.WorkbenchEnvironment::FILE." has an APP_KEY; unchanged.\n");
} elseif (file_put_contents($file, $settings) === false) {
    fwrite(STDERR, 'workbench env: cannot write '.WorkbenchEnvironment::FILE.".\n");
    exit(1);
} else {
    fwrite(STDOUT, sprintf("workbench env: wrote a new APP_KEY into %s%s.\n", WorkbenchEnvironment::FILE, $current === null ? ', made from '.WorkbenchEnvironment::EXAMPLE : ''));
}

$application = $root.'/'.WorkbenchEnvironment::APPLICATION_FILE;
$written = (string) file_get_contents($file);

if (is_dir(dirname($application)) && (! is_file($application) || file_get_contents($application) !== $written)) {
    if (file_put_contents($application, $written) === false) {
        fwrite(STDERR, 'workbench env: cannot write '.WorkbenchEnvironment::APPLICATION_FILE.".\n");
        exit(1);
    }

    fwrite(STDOUT, 'workbench env: copied '.WorkbenchEnvironment::FILE.' to '.WorkbenchEnvironment::APPLICATION_FILE.", the settings Testbench's application reads.\n");
}

exit(0);
