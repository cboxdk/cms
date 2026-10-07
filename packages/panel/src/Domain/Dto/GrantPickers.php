<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The pickers of the grants page's form (PRD 5.10, 13.4), the optional prop `pickers` of
 * Access/Grants in js/panel, which the page asks for when the form to assign a grant opens: the
 * reads of actor.list, role.list and node.list as the person, each the result as the query's
 * result codec wrote it or the problem details of a rejected read (PickerRead), so each picker
 * shows its options, or why it has none, on its own. Its JSON form is
 * access-grant-pickers.v1.json in packages/panel/resources/schemas/pages, written only by the
 * generated GrantPickersCodecV1 (GUARDRAILS 2.2).
 */
#[Internal]
final readonly class GrantPickers
{
    public function __construct(
        public PickerRead $actors,
        public PickerRead $nodes,
        public PickerRead $roles,
    ) {}
}
