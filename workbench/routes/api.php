<?php

declare(strict_types=1);

use Cbox\Cms\Mcp\Adapter\McpRoutes;
use Laravel\Mcp\Server\Registrar;

/*
 * The workbench's API routes, loaded by Testbench with the api middleware group because
 * testbench.yaml discovers them: the MCP surface's endpoint (GUARDRAILS 2.1) at POST /mcp, outside
 * the web group, whose CSRF check would refuse an agent's POST.
 */

McpRoutes::register(app(Registrar::class));
