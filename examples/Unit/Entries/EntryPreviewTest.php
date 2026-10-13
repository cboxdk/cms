<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Content\ReleaseState;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Core\Codecs\Boundary\Generated\EntryPreviewCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\PreviewEntryCodecV1;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Entries\Domain\Dto\EntryPreview;
use Cbox\Cms\Core\Entries\Domain\Queries\PreviewEntry;

// The editor's Preview reads entry.preview: the draft of a variant, or the revision a caller names,
// as the record the delivery API would send once it is released. The record is under data, as the
// type's generated codec wrote it at the public classification access, so the result is the same
// for every reader who may read the entry.

const EXAMPLE_PREVIEW = '{"data":{"cms_id":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a41","title":"Harbour, with gulls"},"draft":3,'
    .'"entry":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a41","released":2,"revision":3,"state":"released","type":"app:story","variant":"shared"}';

it('reads entry.preview from JSON, the draft when no revision is named, and writes the record as the delivery API holds it', function (): void {
    $codec = new PreviewEntryCodecV1;
    $draft = $codec->decode('{"entry":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a41"}', ClassificationAccess::Public);
    $released = $codec->decode('{"entry":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a41","revision":2}', ClassificationAccess::Public);
    $preview = new EntryPreviewCodecV1()->decode(EXAMPLE_PREVIEW, ClassificationAccess::Public);

    expect($draft)->toBeInstanceOf(PreviewEntry::class)
        ->and([$draft->variant, $draft->revision])->toBe([null, null])
        ->and($released->revision?->value)->toBe(2)
        ->and($codec->encode($draft, ClassificationAccess::Public))->toBe('{"entry":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a41","revision":null,"variant":null}')
        ->and($preview)->toBeInstanceOf(EntryPreview::class)
        ->and([$preview->revision->value, $preview->released?->value, $preview->state])->toBe([3, 2, ReleaseState::Released])
        ->and(json_decode($preview->data->value, true, flags: JSON_THROW_ON_ERROR))->toBe(['cms_id' => '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a41', 'title' => 'Harbour, with gulls'])
        ->and(new EntryPreviewCodecV1()->encode($preview, ClassificationAccess::Public))->toBe(EXAMPLE_PREVIEW)
        ->and(static fn (): PreviewEntry => $codec->decode('{"entry":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a41","revision":0}', ClassificationAccess::Public))->toThrow(DecodingFailed::class);
});
