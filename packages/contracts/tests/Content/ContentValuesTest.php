<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Content;

use Cbox\Cms\Contracts\Content\InvalidContentValue;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Ids\EntryId;
use DateTimeImmutable;

/*
 * The content values the mutations carry (PRD 5.4, 5.7, 10): locales in canonical case, variant
 * keys, revision numbers, variant references and time windows.
 */

it('holds a locale in its canonical case', function (string $given, string $canonical): void {
    expect(new Locale($given)->value)->toBe($canonical)
        ->and(new Locale($given)->equals(new Locale($canonical)))->toBeTrue();
})->with([
    ['da', 'da'],
    ['EN-gb', 'en-GB'],
    ['sr-latn', 'sr-Latn'],
    ['sr-LATN-rs', 'sr-Latn-RS'],
    ['es-419', 'es-419'],
    ['fil', 'fil'],
]);

it('refuses a locale that is not a language, script and region tag', function (string $given): void {
    expect(static fn (): Locale => new Locale($given))
        ->toThrow(InvalidContentValue::class, sprintf('A locale is a BCP 47 tag of a language, an optional script and an optional region, such as "da", "en-GB" or "sr-Latn", got "%s".', $given));
})->with(['', 'd', 'dansk', 'en_GB', 'en-G', 'en-GBR', 'en-GB ', 'en-GB-x', 'da-Latn-DK-1']);

it('tells the shared variant from a language variant', function (): void {
    $shared = VariantKey::shared();
    $danish = VariantKey::of(new Locale('da'));

    expect($shared->value)->toBe('shared')
        ->and($shared->isShared())->toBeTrue()
        ->and($shared->locale)->toBeNull()
        ->and($danish->value)->toBe('da')
        ->and($danish->isShared())->toBeFalse()
        ->and($danish->locale?->value)->toBe('da')
        ->and(VariantKey::fromString('shared')->equals($shared))->toBeTrue()
        ->and(VariantKey::fromString('EN-gb')->value)->toBe('en-GB')
        ->and($shared->equals($danish))->toBeFalse()
        ->and(static fn (): VariantKey => VariantKey::fromString('Shared'))->toThrow(InvalidContentValue::class, 'got "Shared"');
});

it('numbers revisions from 1', function (): void {
    expect(RevisionNumber::first()->value)->toBe(1)
        ->and(RevisionNumber::first()->next()->value)->toBe(2)
        ->and(new RevisionNumber(7)->equals(new RevisionNumber(7)))->toBeTrue()
        ->and(new RevisionNumber(7)->equals(new RevisionNumber(8)))->toBeFalse()
        ->and(static fn (): RevisionNumber => new RevisionNumber(0))->toThrow(InvalidContentValue::class, 'A revision number starts at 1, got 0.');
});

it('references a variant by its entry and key', function (): void {
    $entry = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-5a6b7c8d9e0f');
    $other = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-5a6b7c8d9e10');
    $ref = new VariantRef($entry, VariantKey::shared());

    expect($ref->aggregateKey())->toBe('variant:01936f5e-8a2b-7c3d-9e4f-5a6b7c8d9e0f:shared')
        ->and($ref->equals(new VariantRef($entry, VariantKey::shared())))->toBeTrue()
        ->and($ref->equals(new VariantRef($entry, VariantKey::of(new Locale('da')))))->toBeFalse()
        ->and($ref->equals(new VariantRef($other, VariantKey::shared())))->toBeFalse();
});

it('holds a time window in UTC with its start before its end', function (): void {
    $from = new DateTimeImmutable('2026-10-01T08:00:00+02:00');
    $until = new DateTimeImmutable('2026-10-02T06:00:00Z');
    $window = new TimeWindow($from, $until);

    expect($window->from?->format('Y-m-d\TH:i:sP'))->toBe('2026-10-01T06:00:00+00:00')
        ->and($window->until?->getTimezone()->getName())->toBe('UTC')
        ->and($window->contains(new DateTimeImmutable('2026-10-01T06:00:00Z')))->toBeTrue()
        ->and($window->contains(new DateTimeImmutable('2026-10-01T05:59:59.999999Z')))->toBeFalse()
        ->and($window->contains(new DateTimeImmutable('2026-10-02T05:59:59Z')))->toBeTrue()
        ->and($window->contains(new DateTimeImmutable('2026-10-02T06:00:00Z')))->toBeFalse()
        ->and(TimeWindow::always()->contains(new DateTimeImmutable('1970-01-01T00:00:00Z')))->toBeTrue()
        ->and(new TimeWindow(from: $from)->contains(new DateTimeImmutable('2999-01-01T00:00:00Z')))->toBeTrue()
        ->and(new TimeWindow(until: $until)->contains(new DateTimeImmutable('1970-01-01T00:00:00Z')))->toBeTrue()
        ->and($window->equals(new TimeWindow(new DateTimeImmutable('2026-10-01T06:00:00Z'), $until)))->toBeTrue()
        ->and($window->equals(new TimeWindow($from)))->toBeFalse()
        ->and($window->equals(new TimeWindow(until: $until)))->toBeFalse()
        ->and($window->equals(new TimeWindow($from, new DateTimeImmutable('2026-10-02T06:00:00.000001Z'))))->toBeFalse()
        ->and(TimeWindow::always()->equals(new TimeWindow))->toBeTrue()
        ->and(static fn (): TimeWindow => new TimeWindow($until, $from))->toThrow(InvalidContentValue::class, 'A time window starts before it ends, got from 2026-10-02T06:00:00.000+00:00 until 2026-10-01T06:00:00.000+00:00.')
        ->and(static fn (): TimeWindow => new TimeWindow($until, $until))->toThrow(InvalidContentValue::class, 'A time window starts before it ends');
});
