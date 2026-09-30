<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Content;

use Cbox\Cms\Contracts\Content\InvalidContentValue;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementCanonicalSet;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementLocaleAdded;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementWindowSet;

/*
 * A slug is one segment of a path (PRD 5.9), a locale identifies a language in an event, and the
 * placement mutations name their placement; a window makes a placement public, and no window hides
 * it (invariant 18).
 */

it('takes one segment of a path as a slug, compared exactly', function (string $value): void {
    expect(new Slug($value)->value)->toBe($value)
        ->and(new Slug($value)->equals(new Slug($value)))->toBeTrue();
})->with(['harbour', 'Harbour-Opens_2026', 'æble', 'a:b', '...', str_repeat('a', Slug::MAX_LENGTH)]);

it('refuses a slug that is not one segment of a path', function (string $value): void {
    expect(fn (): Slug => new Slug($value))->toThrow(InvalidContentValue::class, 'A slug is 1 to 255 characters');
})->with(['', '.', '..', 'a/b', 'a b', "a\tb", str_repeat('a', Slug::MAX_LENGTH + 1)]);

it('compares slugs by their bytes', function (): void {
    expect(new Slug('Harbour')->equals(new Slug('harbour')))->toBeFalse();
});

it('gives a locale\'s canonical tag as its identifier', function (): void {
    expect(new Locale('EN-gb')->toString())->toBe('en-GB');
});

it('names the placement a placement mutation changes, and makes it public with a window only', function (): void {
    $placement = PlacementId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000801');
    $da = new Locale('da');

    expect(new PlacementLocaleAdded($placement, $da, new Slug('harbour'), true)->aggregate())->toBe($placement)
        ->and(new PlacementCanonicalSet($placement, $da, false)->aggregate())->toBe($placement)
        ->and(new PlacementWindowSet($placement, $da, TimeWindow::always())->makesPublic())->toBeTrue()
        ->and(new PlacementWindowSet($placement, $da, null)->makesPublic())->toBeFalse();
});
