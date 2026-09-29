<?php

declare(strict_types=1);

/*
 * `composer docs:errors`: writes the error reference, docs/reference/errors.md, from the error
 * catalog Cbox\Cms\Contracts\Errors\ErrorCode, with Cbox\Cms\Tooling\Docs\Domain\ErrorReferencePage.
 *
 *   php tools/bin/docs-errors.php [--root=<dir>]
 *
 * It writes the page of this checkout's catalog into the tree below --root, by default this
 * repository, and writes only when the bytes differ, so a second run changes nothing. Gate 10,
 * `composer docs:check`, fails when the page differs from what this script writes.
 *
 * Exits 0 when the page is current or was written, 1 when the page cannot be written, and 2 on a
 * usage error.
 */

use Cbox\Cms\Tooling\Check\Boundary\CommandLine;
use Cbox\Cms\Tooling\Docs\Boundary\DocsErrorsOptions;
use Cbox\Cms\Tooling\Docs\Domain\ErrorReferencePage;

$repository = (string) realpath(dirname(__DIR__, 2));

require $repository.'/vendor/autoload.php';

try {
    $options = DocsErrorsOptions::parse(CommandLine::arguments());
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage()."\n".DocsErrorsOptions::USAGE."\n");
    exit(2);
}

$page = ErrorReferencePage::current();
$path = rtrim($options->root ?? $repository, '/').'/'.ErrorReferencePage::PATH;

if (is_file($path) && file_get_contents($path) === $page) {
    fwrite(STDOUT, 'docs:errors: '.ErrorReferencePage::PATH." is current.\n");
    exit(0);
}

if (! is_dir(dirname($path)) || file_put_contents($path, $page) === false) {
    fwrite(STDERR, 'docs:errors: cannot write '.ErrorReferencePage::PATH.".\n");
    exit(1);
}

fwrite(STDOUT, 'docs:errors: wrote '.ErrorReferencePage::PATH.".\n");

exit(0);
