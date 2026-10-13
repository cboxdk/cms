<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Core\Entries\Domain\Commands\DeleteVariant;

// An editor deletes the Danish translation of an entry at the version of the Danish variant the
// editor read. The kernel withdraws the variant and the entry's placements in da, so their URLs
// answer 410, and leaves the entry, its shared variant and its other languages as they are.

it('deletes the Danish variant at the version the editor read', function (): void {
    $entry = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000111');

    $delete = new DeleteVariant($entry, VariantKey::of(new Locale('da')), new AggregateVersion(5));

    expect($delete->ref()->aggregateKey())->toBe('variant:01936f5e-8a2b-7c3d-9e4f-000000000111:da')
        ->and($delete->expectedVersions()->of($delete->ref()))->toEqual(ReadVersion::at($delete->ref(), new AggregateVersion(5)));
});
