<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\ErrorEntry;

/**
 * Writes the error reference, docs/reference/errors.md, from the entries of the error catalog
 * (PRD 6.1, GUARDRAILS 2.1, 7.2): a table of every code, then a section per code with its heading
 * the code, so ErrorEntry::docs() points at it, and its explanation, HTTP status, CLI exit code,
 * MCP response and whether a retry makes sense. `composer docs:errors` writes it, and gate 10
 * (`composer docs:check`) reports a page that differs from what the catalog gives. The entries are
 * sorted by code, so the same catalog always gives the same bytes.
 */
final readonly class ErrorReferencePage
{
    /**
     * The path of the page below the repository root.
     */
    public const string PATH = ErrorEntry::DOCS_PAGE;

    /**
     * The command that writes the page.
     */
    public const string COMMAND = 'composer docs:errors';

    /**
     * The page for the entries of the whole catalog.
     */
    public static function current(): string
    {
        return self::render(array_map(static fn (ErrorCode $code): ErrorEntry => $code->entry(), ErrorCode::cases()));
    }

    /**
     * @param  list<ErrorEntry>  $entries
     */
    public static function render(array $entries): string
    {
        usort($entries, static fn (ErrorEntry $a, ErrorEntry $b): int => strcmp($a->code->value, $b->code->value));

        $lines = [
            '---',
            'title: Error reference',
            'weight: 61',
            'description: Every error code of the kernel, with its HTTP status, CLI exit code and MCP response, whether a retry makes sense, and what to do.',
            '---',
            '',
            '# Error reference',
            '',
            '`'.self::COMMAND.'` writes this page from the error catalog, `'.ErrorCode::class.'`, and `composer docs:check` fails when the page differs from what it writes. Change the catalog and run it again; do not edit the page by hand. [Error codes](../addons/errors.md) describes the catalog and its types.',
            '',
            'Every error of the kernel has one of these codes. A code is stable and never renamed. Each surface answers a call that ends with a code as its entry says: the REST API with the HTTP status, the `cms:*` commands with the exit code, and the MCP server with the MCP response. Retry says whether sending the same call again later can succeed.',
            '',
            '## Overview',
            '',
            '| Code | HTTP | Exit | MCP | Retry |',
            '|---|---|---|---|---|',
        ];

        foreach ($entries as $entry) {
            $lines[] = sprintf(
                '| [`%s`](#%s) | %d | %d | %s | %s |',
                $entry->code->value,
                $entry->code->value,
                $entry->http->value,
                $entry->exit->value,
                $entry->mcp->value,
                $entry->retryable ? 'yes' : 'no',
            );
        }

        $lines[] = '';
        $lines[] = '## Codes';

        foreach ($entries as $entry) {
            $lines[] = '';
            $lines[] = '### '.$entry->code->value;
            $lines[] = '';
            $lines[] = $entry->explanation;
            $lines[] = '';
            $lines[] = sprintf('- HTTP status: %d %s', $entry->http->value, $entry->http->reason());
            $lines[] = sprintf('- CLI exit code: %d (%s)', $entry->exit->value, $entry->exit->symbol());
            $lines[] = '- MCP: '.$entry->mcp->describe();
            $lines[] = '- Retry: '.($entry->retryable ? 'yes, the same call may succeed later' : 'no, the same call gives the same answer until something changes');
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * The finding for a tree whose page is missing or is not the expected page, which is the page
     * of this checkout's catalog, current(); none when it is.
     *
     * @return list<Finding>
     */
    public static function findings(DocsTree $tree, string $expected): array
    {
        $page = $tree->files->contents(self::PATH);

        return match (true) {
            $page === null => [Finding::about(self::PATH, 'missing; write it from the error catalog with '.self::COMMAND)],
            $page !== $expected => [Finding::about(self::PATH, 'differs from what the error catalog gives; run '.self::COMMAND.' and do not edit the page by hand')],
            default => [],
        };
    }
}
