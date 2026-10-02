<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Cdn;

use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Cdn\CdnPurge;
use Cbox\Cms\Contracts\Cdn\CdnPurgeResult;
use Cbox\Cms\Contracts\Cdn\CdnUnavailable;
use Cbox\Cms\Contracts\Cdn\InvalidCdnPurge;
use Cbox\Cms\Contracts\Cdn\PurgeMode;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use RuntimeException;

/*
 * The values of the CDN driver's contract (PRD 8.12 points 3 and 5).
 */

it('keeps a purge\'s keys in the order given, each once', function (): void {
    $node = DependencyKey::node(NodeId::fromString('01960000-0000-7000-8000-00000000000b'));
    $entry = DependencyKey::entry(EntryId::fromString('01960000-0000-7000-8000-00000000000a'));
    $purge = new CdnPurge([$node, $entry, $node], PurgeMode::Soft);

    expect($purge->keyStrings())->toBe(['n-01960000-0000-7000-8000-00000000000b', 'e-01960000-0000-7000-8000-00000000000a'])
        ->and($purge->mode)->toBe(PurgeMode::Soft);
});

it('refuses a purge without keys and a result without a request', function (): void {
    $purge = new CdnPurge([DependencyKey::entry(EntryId::fromString('01960000-0000-7000-8000-00000000000a'))], PurgeMode::Hard);

    expect(fn (): CdnPurge => new CdnPurge([], PurgeMode::Hard))->toThrow(InvalidCdnPurge::class, 'at least one surrogate key')
        ->and(fn (): CdnPurgeResult => new CdnPurgeResult($purge, 0))->toThrow(InvalidCdnPurge::class, 'at least one request, got 0')
        ->and(new CdnPurgeResult($purge, 1)->requests)->toBe(1);
});

it('says why the CDN did not take a purge and keeps the cause', function (): void {
    $cause = new RuntimeException('HTTP 503');
    $unavailable = CdnUnavailable::because('the purge API answered 503.', $cause);

    expect($unavailable->getMessage())->toBe('The CDN did not take the purge: the purge API answered 503.')
        ->and($unavailable->getPrevious())->toBe($cause);
});

it('says why the CDN did not take a purge, keeps the cause and has the exception code 0', function (): void {
    $cause = new RuntimeException('503');
    $unavailable = CdnUnavailable::because('the purge API answered 503', $cause);

    expect($unavailable->getMessage())->toBe('The CDN did not take the purge: the purge API answered 503')
        ->and($unavailable->getCode())->toBe(0)
        ->and($unavailable->getPrevious())->toBe($cause);
});
