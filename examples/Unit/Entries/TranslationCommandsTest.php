<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateVariantFrom;

// A translator starts the German variant of an entry from revision 3 of the Danish one: the new
// variant is expected absent and the Danish variant at the version the translator read. The
// kernel records the derivation translated_from (da, 3), and entry.get shows it as stale once the
// Danish variant is released with other content.

it('creates the German variant from a revision of the Danish one', function (): void {
    $entry = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000111');

    $create = new CreateVariantFrom($entry, VariantKey::of(new Locale('de')), VariantKey::of(new Locale('da')), new RevisionNumber(3), new AggregateVersion(4));

    expect($create->expectedVersions()->of($create->target()))->toEqual(ReadVersion::absent($create->target()))
        ->and($create->expectedVersions()->of($create->origin()))->toEqual(ReadVersion::at($create->origin(), new AggregateVersion(4)));
});
