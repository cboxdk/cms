<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Docs;

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\ErrorEntry;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Contracts\Errors\HttpStatus;
use Cbox\Cms\Contracts\Errors\McpResponse;
use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\ComposerScripts;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tooling\Docs\Boundary\DocsErrorsOptions;
use Cbox\Cms\Tooling\Docs\Boundary\LocalDocsTree;
use Cbox\Cms\Tooling\Docs\Domain\DocsTree;
use Cbox\Cms\Tooling\Docs\Domain\ErrorReferencePage;
use Cbox\Cms\Tooling\Docs\Domain\Finding;
use Cbox\Cms\Tooling\Docs\Domain\PhpFile;
use Cbox\Cms\Tooling\Docs\Domain\RepositoryFiles;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

/*
 * docs/reference/errors.md is written from the error catalog by `composer docs:errors`
 * (tools/bin/docs-errors.php, PRD 6.1, GUARDRAILS 7.2). The committed page must be exactly what the
 * catalog gives now, and gate 10 (`composer docs:check`) reports a page that is missing or stale,
 * so a code added to the catalog without running the script fails here and in the gate.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

function errorsRead(string $path): string
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException("Cannot read {$path}.");
    }

    return $contents;
}

/**
 * Runs tools/bin/docs-errors.php of this checkout with the arguments.
 *
 * @return array{int, string, string}
 */
function runDocsErrors(string ...$arguments): array
{
    $process = new Process([PHP_BINARY, Phpstan::root().'/tools/bin/docs-errors.php', ...array_values($arguments)], Phpstan::root(), null, null, 120);
    $process->run();

    return [(int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput()];
}

/**
 * Runs tools/bin/docs-check.php of this checkout with the arguments.
 *
 * @return array{int, string}
 */
function runDocsCheckForErrors(string ...$arguments): array
{
    $process = new Process([PHP_BINARY, Phpstan::root().'/tools/bin/docs-check.php', ...array_values($arguments)], Phpstan::root(), null, null, 120);
    $process->run();

    return [(int) $process->getExitCode(), $process->getOutput()];
}

/**
 * The repository's tree with the error reference replaced by the contents, or removed for null.
 */
function errorsTreeWith(?string $page): DocsTree
{
    $tree = LocalDocsTree::read(Phpstan::root());
    $files = new readonly class($tree->files, $page) implements RepositoryFiles
    {
        public function __construct(private RepositoryFiles $files, private ?string $page) {}

        public function contents(string $path): ?string
        {
            return $path === ErrorReferencePage::PATH ? $this->page : $this->files->contents($path);
        }

        public function php(string $path): ?PhpFile
        {
            return $this->files->php($path);
        }

        public function exists(string $path): bool
        {
            return $path === ErrorReferencePage::PATH ? $this->page !== null : $this->files->exists($path);
        }
    };

    return new DocsTree($tree->sources, $tree->schemas, $tree->pages, $tree->examples, $tree->suites, $files, $tree->rootPages, $tree->docsFiles, $tree->docsDirectories, $tree->strayPages);
}

it('commits the error reference that the catalog gives now', function (): void {
    expect(errorsRead(Phpstan::root().'/'.ErrorReferencePage::PATH))->toBe(ErrorReferencePage::current())
        ->and(ErrorReferencePage::PATH)->toBe(ErrorEntry::DOCS_PAGE);
});

it('gives every code a section whose heading is the anchor of the entry\'s docs link, with what each surface answers', function (ErrorCode $code): void {
    $page = ErrorReferencePage::current();
    $entry = $code->entry();
    [, $anchor] = explode('#', $entry->docs(), 2);

    expect($page)->toContain(
        "\n### {$anchor}\n\n{$entry->explanation}\n\n",
        sprintf("- HTTP status: %d %s\n- CLI exit code: %d (%s)\n- MCP: %s\n- Retry: %s\n", $entry->http->value, $entry->http->reason(), $entry->exit->value, $entry->exit->symbol(), $entry->mcp->describe(), $entry->retryable ? 'yes, the same call may succeed later' : 'no, the same call gives the same answer until something changes'),
        sprintf('| [`%s`](#%s) | %d | %d | %s | %s |', $code->value, $anchor, $entry->http->value, $entry->exit->value, $entry->mcp->value, $entry->retryable ? 'yes' : 'no'),
    );
})->with(ErrorCode::cases());

it('renders the same bytes whatever the order of the entries, sorted by code', function (): void {
    $entries = [
        new ErrorEntry(ErrorCode::VersionConflict, HttpStatus::Conflict, ExitCode::DataErr, McpResponse::ToolError, false, 'Read it again.'),
        new ErrorEntry(ErrorCode::DryRun, HttpStatus::Ok, ExitCode::Ok, McpResponse::Result, false, 'Nothing was committed.'),
        new ErrorEntry(ErrorCode::IdempotencyInFlight, HttpStatus::Conflict, ExitCode::TempFail, McpResponse::ToolError, true, 'Try again.'),
    ];
    $page = ErrorReferencePage::render($entries);

    expect(ErrorReferencePage::render(array_reverse($entries)))->toBe($page)
        ->and($page)->toStartWith("---\ntitle: Error reference\nweight: 61\ndescription: ")
        ->and($page)->toEndWith("- Retry: no, the same call gives the same answer until something changes\n")
        ->and(strpos($page, '### dry_run'))->toBeLessThan((int) strpos($page, '### idempotency_in_flight'))
        ->and(strpos($page, '### idempotency_in_flight'))->toBeLessThan((int) strpos($page, '### version_conflict'))
        ->and($page)->toContain(
            "| Code | HTTP | Exit | MCP | Retry |\n|---|---|---|---|---|\n| [`dry_run`](#dry_run) | 200 | 0 | result | no |\n| [`idempotency_in_flight`](#idempotency_in_flight) | 409 | 75 | tool_error | yes |\n",
            "- HTTP status: 409 Conflict\n- CLI exit code: 75 (EX_TEMPFAIL)\n- MCP: a tool result with isError set\n- Retry: yes, the same call may succeed later\n",
        );
});

it('has no finding for the repository, and one for a page that is stale or missing', function (): void {
    $stale = str_replace('idempotency_in_flight', 'idempotency_still_running', ErrorReferencePage::current());

    expect(ErrorReferencePage::findings(errorsTreeWith(ErrorReferencePage::current()), ErrorReferencePage::current()))->toBe([])
        ->and(ErrorReferencePage::findings(LocalDocsTree::read(Phpstan::root()), ErrorReferencePage::current()))->toBe([])
        ->and(array_map(strval(...), ErrorReferencePage::findings(errorsTreeWith($stale), ErrorReferencePage::current())))
        ->toBe(['docs/reference/errors.md: differs from what the error catalog gives; run composer docs:errors and do not edit the page by hand'])
        ->and(array_map(static fn (Finding $finding): string => (string) $finding, ErrorReferencePage::findings(errorsTreeWith(null), ErrorReferencePage::current())))
        ->toBe(['docs/reference/errors.md: missing; write it from the error catalog with composer docs:errors']);
});

it('writes the page into a tree, and a second run changes nothing', function (): void {
    $root = ScratchDirectory::make();
    ScratchDirectory::write($root.'/'.ErrorReferencePage::PATH, "stale\n");

    [$exit, $output, $errors] = runDocsErrors('--root='.$root);

    expect([$exit, $errors])->toBe([0, ''])
        ->and($output)->toBe("docs:errors: wrote docs/reference/errors.md.\n")
        ->and(errorsRead($root.'/'.ErrorReferencePage::PATH))->toBe(ErrorReferencePage::current());

    [$exit, $output] = runDocsErrors('--root='.$root);

    expect($exit)->toBe(0)
        ->and($output)->toBe("docs:errors: docs/reference/errors.md is current.\n")
        ->and(errorsRead($root.'/'.ErrorReferencePage::PATH))->toBe(ErrorReferencePage::current());
});

it('exits 1 when the page cannot be written and 2 on a usage error', function (): void {
    $root = ScratchDirectory::make();

    [$missing, , $missingErrors] = runDocsErrors('--root='.$root);
    [$usage, , $usageErrors] = runDocsErrors('--fix');

    expect([$missing, $missingErrors])->toBe([1, "docs:errors: cannot write docs/reference/errors.md.\n"])
        ->and(is_file($root.'/'.ErrorReferencePage::PATH))->toBeFalse()
        ->and([$usage, $usageErrors])->toBe([2, "Unknown or repeated argument [--fix].\n".DocsErrorsOptions::USAGE."\n"]);
});

it('parses --root once, and refuses anything else', function (): void {
    expect(DocsErrorsOptions::parse([])->root)->toBeNull()
        ->and(DocsErrorsOptions::parse(['--root=/tmp/tree'])->root)->toBe('/tmp/tree')
        ->and(fn (): DocsErrorsOptions => DocsErrorsOptions::parse(['--root=']))->toThrow(InvalidArgumentException::class, 'Unknown or repeated argument [--root=].')
        ->and(fn (): DocsErrorsOptions => DocsErrorsOptions::parse(['--root=a', '--root=b']))->toThrow(InvalidArgumentException::class, 'Unknown or repeated argument [--root=b].');
});

it('fails gate 10 on a tree whose error reference is stale or missing, and names the page', function (): void {
    $root = ScratchDirectory::make();
    ScratchDirectory::write($root.'/phpunit.xml', errorsRead(Phpstan::root().'/phpunit.xml'));
    ScratchDirectory::write($root.'/'.ErrorReferencePage::PATH, ErrorReferencePage::current());

    [$currentExit, $currentOutput] = runDocsCheckForErrors('--root='.$root);

    ScratchDirectory::write($root.'/'.ErrorReferencePage::PATH, ErrorReferencePage::current()."\nEdited by hand.\n");
    [$staleExit, $staleOutput] = runDocsCheckForErrors('--root='.$root);

    unlink($root.'/'.ErrorReferencePage::PATH);
    [$missingExit, $missingOutput] = runDocsCheckForErrors('--root='.$root);

    expect($currentExit)->toBe(1)
        ->and($currentOutput)->toContain('docs/reference: the folder has no _index.md')
        ->and($currentOutput)->not->toContain('docs/reference/errors.md: ')
        ->and($staleExit)->toBe(1)
        ->and(explode("\n", $staleOutput))->toContain('docs/reference/errors.md: differs from what the error catalog gives; run composer docs:errors and do not edit the page by hand')
        ->and($missingExit)->toBe(1)
        ->and(explode("\n", $missingOutput))->toContain('docs/reference/errors.md: missing; write it from the error catalog with composer docs:errors');
});

it('runs the script as composer docs:errors, with a description', function (): void {
    expect(ComposerScripts::steps('docs:errors'))->toBe(['@php tools/bin/docs-errors.php'])
        ->and(ComposerScripts::description('docs:errors'))->toContain('docs/reference/errors.md', '--root=<dir>')
        ->and(ComposerScripts::description('docs:check'))->toContain('docs/reference/errors.md');
});
