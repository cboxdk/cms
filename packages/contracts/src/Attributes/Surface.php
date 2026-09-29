<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Attributes;

/**
 * A surface an action can be exposed on (GUARDRAILS 2.1). #[Action] lists them, and cms:build
 * generates each surface's routes, tools and signatures from the registry. Each surface builds
 * the Envelope of a call and translates the typed result for its transport.
 */
#[Experimental]
enum Surface: string
{
    /** REST with JSON and problem details. */
    case Rest = 'rest';

    /** The panel's Inertia pages, with redirects and errors in page props. */
    case Inertia = 'inertia';

    /** MCP tools, for agents. */
    case Mcp = 'mcp';

    /** cms:* commands, with exit codes from the error catalog. */
    case Cli = 'cli';
}
