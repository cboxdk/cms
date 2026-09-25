<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Attributes;

/**
 * A transport that cms:build generates for an action (GUARDRAILS 2.1). Each surface has its
 * own transport profile for results and errors.
 *
 * Internal callers, jobs and the scheduler call an action directly and need no surface.
 */
#[Experimental]
enum Surface: string
{
    /** REST with JSON and problem details, and the generated OpenAPI document. */
    case Rest = 'rest';

    /** The panel: Inertia pages, redirects and the command palette. */
    case Inertia = 'inertia';

    /** An MCP tool. */
    case Mcp = 'mcp';

    /** A CLI command with exit codes from the error catalogue. */
    case Cli = 'cli';
}
