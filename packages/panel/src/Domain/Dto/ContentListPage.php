<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Codecs\JsonDocument;
use Cbox\Cms\Contracts\Content\ReleaseState;
use Cbox\Cms\Contracts\Schema\TypeName;

/**
 * The props of the content list (PRD 5.4, 13.4), Content/List in js/panel: the address the logout
 * posts to, the name of the type the page lists, or null for every type, the release state it is
 * filtered by, or null, and the read of entry.list as the person: its result, the document of
 * entry.list.result.v1.json the query's result codec wrote at the person's classification access,
 * or the rejection, the problem details (problem.v1.json) of a rejected read, one of the two and
 * never both. Its JSON form is content-list.v1.json in packages/panel/resources/schemas/pages,
 * written only by the generated ContentListPageCodecV1 (GUARDRAILS 2.2).
 */
#[Internal]
final readonly class ContentListPage
{
    public function __construct(
        public string $logout,
        public ?TypeName $type,
        public ?ReleaseState $state,
        public ?JsonDocument $result,
        public ?JsonDocument $rejection,
    ) {}
}
