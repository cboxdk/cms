<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Codecs\JsonDocument;
use Cbox\Cms\Contracts\Content\Locale;

/**
 * The props of the grants page (PRD 5.10, 13.4), Access/Grants in js/panel: the address the logout
 * posts to; the read of grant.list as the person: its result, the document of
 * grant.list.result.v1.json the query's result codec wrote at the person's classification access,
 * or the rejection, the problem details (problem.v1.json) of a rejected read, one of the two and
 * never both; and the languages the installation's sites publish in (cbox-cms.sites), sorted and
 * each once, which the form offers as the locales of a grant. The pickers' reads are the optional
 * prop beside these (GrantPickers), not part of the page's own props. Its JSON form is
 * access-grants.v1.json in packages/panel/resources/schemas/pages, written only by the generated
 * AccessGrantsPageCodecV1 (GUARDRAILS 2.2).
 */
#[Internal]
final readonly class AccessGrantsPage
{
    /**
     * @param  list<Locale>  $locales  the locales of the configured sites, sorted by tag, each once
     */
    public function __construct(
        public string $logout,
        public ?JsonDocument $result,
        public ?JsonDocument $rejection,
        public array $locales,
    ) {}
}
