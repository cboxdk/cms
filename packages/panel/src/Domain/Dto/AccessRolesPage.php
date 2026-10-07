<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Codecs\JsonDocument;

/**
 * The props of the roles page (PRD 5.10, 13.4), Access/Roles in js/panel: the address the logout
 * posts to, and the read of role.list as the person: its result, the document of
 * role.list.result.v1.json the query's result codec wrote at the person's classification access,
 * or the rejection, the problem details (problem.v1.json) of a rejected read, one of the two and
 * never both. Its JSON form is access-roles.v1.json in packages/panel/resources/schemas/pages,
 * written only by the generated AccessRolesPageCodecV1 (GUARDRAILS 2.2).
 */
#[Internal]
final readonly class AccessRolesPage
{
    public function __construct(
        public string $logout,
        public ?JsonDocument $result,
        public ?JsonDocument $rejection,
    ) {}
}
