<?php

declare(strict_types=1);

namespace Cbox\Cms\Mcp\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;

/**
 * An action the registry exposes on Surface::Mcp that the MCP surface cannot offer as a tool, and
 * why: no codec reads its command or query, so the surface has no JSON Schema to describe its
 * arguments with and no codec to read them (GUARDRAILS 2.2).
 */
#[Internal]
final readonly class UndescribedTool
{
    public function __construct(
        public ActionEntry $action,
        public string $reason,
    ) {}
}
