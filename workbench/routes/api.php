<?php

declare(strict_types=1);

use Cbox\Cms\Http\Delivery\DeliveryRoutes;
use Cbox\Cms\Mcp\Adapter\McpRoutes;
use Illuminate\Contracts\Routing\Registrar as RouteRegistrar;
use Laravel\Mcp\Server\Registrar;

/*
 * The workbench's API routes, loaded by Testbench with the api middleware group because
 * testbench.yaml discovers them: the MCP surface's endpoint (GUARDRAILS 2.1) at POST /mcp, outside
 * the web group, whose CSRF check would refuse an agent's POST, and the delivery API's resolve at
 * GET /v1/resolve (PRD 8.9, 8.10), outside the web group, whose session and cookies would make
 * every answer private.
 */

McpRoutes::register(app(Registrar::class));
DeliveryRoutes::register(app(RouteRegistrar::class));
