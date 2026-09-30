<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Fragments;

use Cbox\Cms\Contracts\Attributes\Subscription;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Core\Entries\Domain\Events\EntryCreated;
use Cbox\Cms\Core\Entries\Domain\Events\VariantRevised;
use Cbox\Cms\Core\Fragments\Actions\InvalidateFragments;
use Cbox\Cms\Core\Fragments\Boundary\InvalidationConfig;
use Cbox\Cms\Core\Fragments\Domain\Dto\InvalidationSettings;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Config\Repository;
use InvalidArgumentException;
use ReflectionClass;

/*
 * The invalidation subscriber's declaration and settings: its #[Subscription] on the critical lane
 * with the projection origin, the fence's length and the configuration reader.
 */

it('declares fragments.invalidate on the critical lane for the content events, acknowledging origin', function (): void {
    $attributes = new ReflectionClass(InvalidateFragments::class)->getAttributes(Subscription::class);

    expect($attributes)->toHaveCount(1);

    $subscription = $attributes[0]->newInstance();

    expect($subscription->name)->toBe('fragments.invalidate')
        ->and($subscription->lane)->toBe(Lane::Critical)
        ->and($subscription->projection)->toBe('origin')
        ->and($subscription->events)->toBe([EntryCreated::class, VariantRevised::class]);
});

it('is in the compiled registry, so a receipt of a content event lists origin', function (): void {
    $registry = app(CompiledRegistry::class);

    expect(array_map(static fn (ProjectionName $name): string => $name->value, $registry->projectionsFor(VariantRevised::class)))->toContain('origin')
        ->and(array_map(static fn (ProjectionName $name): string => $name->value, $registry->projectionsFor(EntryCreated::class)))->toContain('origin');
});

it('keeps a fence for its seconds', function (): void {
    $start = new DateTimeImmutable('2026-03-10T12:00:00Z');

    expect(new InvalidationSettings(90)->fence())->toEqual(new DateInterval('PT90S'))
        ->and($start->add(new InvalidationSettings(90)->fence())->format(DATE_ATOM))->toBe('2026-03-10T12:01:30+00:00')
        ->and(new InvalidationSettings()->fenceSeconds)->toBe(60);
});

it('refuses a fence outside 1 to 86400 seconds', function (int $seconds): void {
    expect(fn (): InvalidationSettings => new InvalidationSettings($seconds))
        ->toThrow(InvalidArgumentException::class, sprintf('cbox-cms.fragments.fence_seconds must be a whole number from 1 to 86400; it is %d.', $seconds));
})->with([0, -1, 86_401]);

it('takes a fence of 1 and of 86400 seconds', function (int $seconds): void {
    expect(new InvalidationSettings($seconds)->fenceSeconds)->toBe($seconds);
})->with([1, 86_400]);

it('reads the settings from cbox-cms.fragments, 60 seconds by default', function (): void {
    expect(InvalidationConfig::read(new Repository(['cbox-cms' => ['fragments' => ['fence_seconds' => 15]]]))->fenceSeconds)->toBe(15)
        ->and(InvalidationConfig::read(new Repository([]))->fenceSeconds)->toBe(60)
        ->and(app(InvalidationSettings::class)->fenceSeconds)->toBe(60);
});

it('refuses a fence that is not a whole number', function (): void {
    expect(fn (): InvalidationSettings => InvalidationConfig::read(new Repository(['cbox-cms' => ['fragments' => ['fence_seconds' => '60']]])))
        ->toThrow(InvalidArgumentException::class, 'The setting cbox-cms.fragments.fence_seconds must be a whole number; it is string.');
});
