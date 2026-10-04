<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Codecs\JsonDocument;

/**
 * The props of the who-am-I page (PRD 5.16, 13.4), Account/Me in js/panel: the address the logout
 * posts to, and the read of actor.me as the person: its result, the document of
 * actor.me.result.v1.json the query's result codec wrote at the person's classification access,
 * or the rejection, the problem details (problem.v1.json) of a rejected read, one of the two and
 * never both. Its JSON form is account-me.v1.json in packages/panel/resources/schemas/pages, written only by the
 * generated AccountMePageCodecV1 (GUARDRAILS 2.2).
 */
#[Internal]
final readonly class AccountMePage
{
    public function __construct(
        public string $logout,
        public ?JsonDocument $result,
        public ?JsonDocument $rejection,
    ) {}
}
