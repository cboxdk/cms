<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Cache;

use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Cache\DependencyKind;
use Cbox\Cms\Contracts\Cache\Fragment;
use Cbox\Cms\Contracts\Cache\FragmentKey;
use Cbox\Cms\Contracts\Cache\FragmentPurge;
use Cbox\Cms\Contracts\Cache\InvalidCacheValue;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\Uuid7;
use DateTimeImmutable;
use DateTimeZone;

/*
 * The values of the fragment store's contract (PRD 8.12, 9.3, 9.4): dependency keys, fragment
 * keys, fragments and purges.
 */

const CACHE_UUID = '01960000-0000-7000-8000-00000000000a';

const CACHE_OTHER_UUID = '01960000-0000-7000-8000-00000000000b';

it('writes an entry key as e- and a node key as n- with the uuid in lower case, and reads them back', function (): void {
    $entry = DependencyKey::entry(EntryId::fromString(strtoupper(CACHE_UUID)));
    $node = DependencyKey::node(NodeId::fromString(CACHE_UUID));

    expect($entry->toString())->toBe('e-'.CACHE_UUID)
        ->and($node->toString())->toBe('n-'.CACHE_UUID)
        ->and(strlen($entry->toString()))->toBe(DependencyKey::MAX_LENGTH)
        ->and(DependencyKey::fromString('e-'.CACHE_UUID)->equals($entry))->toBeTrue()
        ->and(DependencyKey::fromString('n-'.CACHE_UUID)->kind)->toBe(DependencyKind::Node)
        ->and($entry->equals($node))->toBeFalse();
});

it('refuses a dependency key that is not e- or n- and a lower-case UUIDv7', function (string $value): void {
    expect(fn (): DependencyKey => DependencyKey::fromString($value))->toThrow(InvalidCacheValue::class, 'A dependency key is');
})->with([
    'no kind' => [CACHE_UUID],
    'unknown kind' => ['a-'.CACHE_UUID],
    'upper-case kind' => ['E-'.CACHE_UUID],
    'upper-case uuid' => ['e-'.strtoupper(CACHE_UUID)],
    'no uuid' => ['e-'],
    'not a uuid' => ['e-page'],
    'uuid v4' => ['e-01960000-0000-4000-8000-00000000000a'],
    'empty' => [''],
]);

it('takes a fragment key of 1 to 512 visible ASCII characters and refuses anything else', function (): void {
    expect(new FragmentKey('page:/news?p=2')->value)->toBe('page:/news?p=2')
        ->and(new FragmentKey(str_repeat('k', FragmentKey::MAX_LENGTH))->equals(new FragmentKey(str_repeat('k', 512))))->toBeTrue()
        ->and(fn (): FragmentKey => new FragmentKey(''))->toThrow(InvalidCacheValue::class, 'A fragment key is 1 to 512')
        ->and(fn (): FragmentKey => new FragmentKey(str_repeat('k', 513)))->toThrow(InvalidCacheValue::class)
        ->and(fn (): FragmentKey => new FragmentKey('page /news'))->toThrow(InvalidCacheValue::class)
        ->and(fn (): FragmentKey => new FragmentKey("page:/n\u{e6}"))->toThrow(InvalidCacheValue::class);
});

it('keeps a fragment\'s dependencies sorted and each once, and its validUntil in UTC with the microseconds', function (): void {
    $second = DependencyKey::node(NodeId::fromString(CACHE_UUID));
    $first = DependencyKey::entry(EntryId::fromString(CACHE_OTHER_UUID));
    $fragment = new Fragment(
        new FragmentKey('page:/a'),
        'body',
        [$second, $first, $second],
        new CommitPosition('10'),
        new DateTimeImmutable('2026-09-29T14:00:00.123456+02:00'),
    );

    expect(array_map(static fn (DependencyKey $key): string => $key->toString(), $fragment->dependencies))->toBe([$first->toString(), $second->toString()])
        ->and($fragment->validUntil->format('Y-m-d\TH:i:s.uP'))->toBe('2026-09-29T12:00:00.123456+00:00')
        ->and($fragment->dependsOn($first))->toBeTrue()
        ->and($fragment->dependsOn(DependencyKey::entry(EntryId::fromString(CACHE_UUID))))->toBeFalse();
});

it('refuses a fragment with more than 50 dependencies (PRD 9.6)', function (): void {
    $keys = [];

    for ($i = 0; $i <= Fragment::MAX_DEPENDENCIES; $i++) {
        $keys[] = DependencyKey::entry(new EntryId(Uuid7::lowestAt(1_700_000_000_000 + $i)));
    }

    $at = new DateTimeImmutable('2026-09-29T12:00:00Z');

    expect(new Fragment(new FragmentKey('a'), '', array_slice($keys, 0, 50), new CommitPosition('1'), $at)->dependencies)->toHaveCount(50)
        ->and(fn (): Fragment => new Fragment(new FragmentKey('a'), '', $keys, new CommitPosition('1'), $at))
        ->toThrow(InvalidCacheValue::class, 'at most 50 keys (PRD 9.6), got 51');
});

it('keeps a purge\'s fenceUntil in UTC', function (): void {
    $purge = new FragmentPurge(
        DependencyKey::entry(EntryId::fromString(CACHE_UUID)),
        new CommitPosition('77'),
        new DateTimeImmutable('2026-09-29T12:00:00.5', new DateTimeZone('Europe/Copenhagen')),
    );

    expect($purge->fenceUntil->format('Y-m-d\TH:i:s.uP'))->toBe('2026-09-29T10:00:00.500000+00:00')
        ->and($purge->position->value)->toBe('77');
});

it('names the key and both instants when a fragment or a fence has already ended', function (): void {
    $at = new DateTimeImmutable('2026-09-29T12:00:00Z');
    $key = DependencyKey::entry(EntryId::fromString(CACHE_UUID));

    expect(InvalidCacheValue::fragmentExpired(new FragmentKey('page:/a'), $at, $at)->getMessage())
        ->toBe('The fragment "page:/a" was valid until 2026-09-29T12:00:00.000000+00:00, which is not after the Clock\'s time 2026-09-29T12:00:00.000000+00:00.')
        ->and(InvalidCacheValue::fenceEnded($key, $at, $at)->getMessage())
        ->toBe('The purge fence of "e-'.CACHE_UUID.'" ends at 2026-09-29T12:00:00.000000+00:00, which is not after the Clock\'s time 2026-09-29T12:00:00.000000+00:00.')
        ->and(InvalidCacheValue::fragmentKey(str_repeat('x', 70)."\n")->getMessage())->toContain(str_repeat('x', 64).'..."');
});

it('shows a refused value in full up to 64 bytes and cut after that', function (): void {
    $full = str_repeat('e', 64);

    expect(InvalidCacheValue::dependencyKey($full)->getMessage())->toEndWith("got \"{$full}\".")
        ->and(InvalidCacheValue::dependencyKey($full.'x')->getMessage())->toEndWith("got \"{$full}...\".");
});
