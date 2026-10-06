<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * What a contract version's JSON Schema says about itself (GUARDRAILS 2.2): its title and its
 * description, in English, as action.list lists an action by them. The description is empty for
 * a schema without one.
 */
#[Internal]
final readonly class ContractSummary
{
    public function __construct(
        public string $title,
        public string $description,
    ) {}
}
