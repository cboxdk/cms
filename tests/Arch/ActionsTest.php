<?php

declare(strict_types=1);

use Cbox\Cms\Tests\Support\Arch\ActionPlacement;
use Cbox\Cms\Tests\Support\Arch\Codebase;

/*
 * An action's resolve() and plan() never write (GUARDRAILS 2.1, 4.1, PRD 6.2): every write and
 * query action in packages/src and workbench/app lives in an Actions namespace, where the Arch
 * rules and the PHPStan rules of the layer forbid the framework, the DB facade, connections and
 * transactions. ActionPlacementTest shows the rule finds an action elsewhere.
 */

arch('actions: every WriteAction and QueryAction is in an Actions namespace', function (): void {
    expect(ActionPlacement::violations(Codebase::types()))->toBe([]);
});
