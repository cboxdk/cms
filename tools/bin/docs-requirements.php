<?php

declare(strict_types=1);

/*
 * `composer docs:requirements`: writes docs/requirements.md from composer.json, package.json and
 * compose.yaml, with Cbox\Cms\Tooling\Docs\Domain\RequirementsPage.
 *
 *   php tools/bin/docs-requirements.php [--root=<dir>]
 *
 * It writes the page of the tree below --root, by default this repository, and writes only when
 * the bytes differ, so a second run changes nothing.
 *
 * Exits 0 when the page is current or was written, 1 when a file cannot be read or the page
 * cannot be written, and 2 on a usage error.
 */

use Cbox\Cms\Tooling\Check\Boundary\CommandLine;
use Cbox\Cms\Tooling\Docs\Boundary\DocsRequirementsOptions;
use Cbox\Cms\Tooling\Docs\Boundary\RequirementsSources;
use Cbox\Cms\Tooling\Docs\Domain\RequirementsPage;

$repository = (string) realpath(dirname(__DIR__, 2));

require $repository.'/vendor/autoload.php';

try {
    $options = DocsRequirementsOptions::parse(CommandLine::arguments());
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage()."\n".DocsRequirementsOptions::USAGE."\n");
    exit(2);
}

$root = $options->root ?? $repository;

try {
    $page = RequirementsPage::render(RequirementsSources::read($root));
} catch (UnexpectedValueException $exception) {
    fwrite(STDERR, "docs:requirements: {$exception->getMessage()}\n");
    exit(1);
}

$path = rtrim($root, '/').'/'.RequirementsPage::PATH;

if (is_file($path) && file_get_contents($path) === $page) {
    fwrite(STDOUT, 'docs:requirements: '.RequirementsPage::PATH." is current.\n");
    exit(0);
}

if (! is_dir(dirname($path)) || file_put_contents($path, $page) === false) {
    fwrite(STDERR, 'docs:requirements: cannot write '.RequirementsPage::PATH.".\n");
    exit(1);
}

fwrite(STDOUT, 'docs:requirements: wrote '.RequirementsPage::PATH.".\n");

exit(0);
