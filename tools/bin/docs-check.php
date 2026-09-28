<?php

declare(strict_types=1);

/*
 * `composer docs:check`: gate 10 of GUARDRAILS 10, documentation with running examples for every
 * public extension point (GUARDRAILS 2.4, PRD 14.4), in the layout of the cboxdk docs standard,
 * with no dangling link and with the screenshots of the manifest.
 *
 *   php tools/bin/docs-check.php [--root=<dir>]
 *
 * It checks the tree below --root, by default this repository, with the rules of
 * Cbox\Cms\Tooling\Docs\Domain\DocsAudit, the exclusions of Docs\Domain\Exclusions and the
 * screenshots of Docs\Domain\Screenshots, and prints each finding on a line of its own:
 * `<file>:<line>: <message>`, or `<subject>: <message>` for an extension point, a file or a folder,
 * such as `<extension point>: undocumented`.
 *
 * Exits 0 when there is no finding, 1 when there is one or the tree cannot be read, and 2 on a
 * usage error.
 */

use Cbox\Cms\Tooling\Check\Boundary\CommandLine;
use Cbox\Cms\Tooling\Docs\Boundary\DocsCheckOptions;
use Cbox\Cms\Tooling\Docs\Boundary\LocalDocsTree;
use Cbox\Cms\Tooling\Docs\Domain\DocsAudit;
use Cbox\Cms\Tooling\Docs\Domain\Exclusions;
use Cbox\Cms\Tooling\Docs\Domain\Screenshots;

$repository = (string) realpath(dirname(__DIR__, 2));

require $repository.'/vendor/autoload.php';

try {
    $options = DocsCheckOptions::parse(CommandLine::arguments());
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage()."\n".DocsCheckOptions::USAGE."\n");
    exit(2);
}

try {
    $tree = LocalDocsTree::read($options->root ?? $repository);
} catch (UnexpectedValueException $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}

$findings = DocsAudit::findings($tree, Exclusions::all(), Screenshots::all());

foreach ($findings as $finding) {
    fwrite(STDOUT, $finding."\n");
}

if ($findings !== []) {
    fwrite(STDERR, sprintf("docs:check: %d %s. Every public extension point needs one page with a running example (GUARDRAILS 2.4, PRD 14.4), and docs/ keeps the layout of the cboxdk docs standard with no dangling link.\n", count($findings), count($findings) === 1 ? 'finding' : 'findings'));
    exit(1);
}

fwrite(STDOUT, "docs:check: every extension point is documented with a running example, docs/ keeps its layout, and no link dangles.\n");

exit(0);
