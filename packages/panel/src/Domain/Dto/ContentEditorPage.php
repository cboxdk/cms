<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Schema\TypeName;

/**
 * The props of the entry editor (PRD 5.4, 8.9, 11.12, 13.4), Content/Editor in js/panel: the address
 * the logout posts to, the name of the type the address names, the entry the page edits, or null
 * for a new entry, the address of the type's content list, the revision the page previews, null for
 * the draft, and the reads as the person: of type.describe, of entry.get and entry.preview for the
 * page of an entry, and of node.list, the nodes a new entry's home and an entry's placements may be
 * below, each the result's document or the problem details of a rejection. Its JSON form is
 * content-editor.v1.json in packages/panel/resources/schemas/pages, written only by the generated
 * ContentEditorPageCodecV1 (GUARDRAILS 2.2).
 */
#[Internal]
final readonly class ContentEditorPage
{
    public function __construct(
        public string $logout,
        public TypeName $type,
        public ?EntryId $entry,
        public string $list,
        public ReadAnswer $typeRead,
        public ?ReadAnswer $entryRead,
        public ReadAnswer $nodesRead,
        public ?ReadAnswer $previewRead,
        public ?int $revision,
    ) {}
}
