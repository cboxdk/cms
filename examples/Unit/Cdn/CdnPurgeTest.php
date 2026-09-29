<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Cdn\CdnPurge;
use Cbox\Cms\Contracts\Cdn\PurgeMode;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Testkit\Cdn\FakeCdnDriver;

// The CDN driver on the testkit's fake. A changeset changed an article and removed another from a
// section. The purge subscriber gathers the keys into one purge per driver: soft for the change,
// hard for the removal (PRD 8.12 points 3 and 5).

it('purges a change softly and a removal hard, each in as few requests as the CDN allows', function (): void {
    $cdn = new FakeCdnDriver(maxKeysPerRequest: 2);
    $changed = DependencyKey::entry(EntryId::fromString('01960000-0000-7000-8000-00000000000a'));
    $removed = DependencyKey::entry(EntryId::fromString('01960000-0000-7000-8000-00000000000b'));
    $section = DependencyKey::node(NodeId::fromString('01960000-0000-7000-8000-00000000000c'));

    $soft = $cdn->purge(new CdnPurge([$changed], PurgeMode::Soft));
    $hard = $cdn->purge(new CdnPurge([$removed, $section, $changed], PurgeMode::Hard));

    expect($soft->applied->mode)->toBe(PurgeMode::Soft)
        ->and($hard->requests)->toBe(2)
        ->and(array_map(static fn (CdnPurge $request): array => $request->keyStrings(), $cdn->requests()))->toBe([
            [$changed->toString()],
            [$removed->toString(), $section->toString()],
            [$changed->toString()],
        ])
        ->and($cdn->purgedWith($changed))->toBe(PurgeMode::Hard);
});

it('purges hard on a CDN that cannot purge softly', function (): void {
    $cdn = new FakeCdnDriver(softPurge: false);
    $changed = DependencyKey::entry(EntryId::fromString('01960000-0000-7000-8000-00000000000a'));

    expect($cdn->purge(new CdnPurge([$changed], PurgeMode::Soft))->applied->mode)->toBe(PurgeMode::Hard);
});
