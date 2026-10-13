<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Codecs\JsonDocument;
use Cbox\Cms\Contracts\Content\ReleaseState;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\Result;
use Cbox\Cms\Contracts\Schema\TypeName;

/**
 * The result of entry.preview (PRD 5.4, 8.9, 13.4): the entry, the name of its type, the variant
 * and the number of the revision the record is of, the numbers of the variant's draft and released
 * revision and its release state, and the record itself under `data`, as the delivery API's answer
 * holds it: the JSON its type's generated codec wrote at the public classification access, so no
 * field above public is in it (invariant 10). Its JSON form is entry.preview.result.v1.json.
 */
#[Experimental]
final readonly class EntryPreview implements Result
{
    public function __construct(
        public EntryId $entry,
        public TypeName $type,
        public VariantKey $variant,
        public RevisionNumber $revision,
        public RevisionNumber $draft,
        public ?RevisionNumber $released,
        public ReleaseState $state,
        public JsonDocument $data,
    ) {}
}
