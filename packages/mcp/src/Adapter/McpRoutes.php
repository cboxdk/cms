<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Adapter;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Illuminate\Routing\Route;
use Laravel\Mcp\Server\Registrar;

/**
 * The route of the MCP surface (GUARDRAILS 2.1): `POST <path>`, the Streamable HTTP endpoint of
 * the installation's MCP server, where an agent lists the tools and calls them with its MCP token
 * as the request's Bearer credential (PRD 22). An application registers it outside the web
 * middleware group, which would refuse the agent's POST without a CSRF token, for example in its
 * API routes: McpRoutes::register(app(Registrar::class)).
 */
#[Experimental]
final readonly class McpRoutes
{
    public const string PATH = 'mcp';

    public const string NAME = 'cbox-cms.mcp';

    public static function register(Registrar $registrar, string $path = self::PATH): Route
    {
        return $registrar->web('/'.trim($path, '/'), McpServer::class)->name(self::NAME);
    }
}
