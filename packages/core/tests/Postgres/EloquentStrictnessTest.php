<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Core\CoreServiceProvider;
use Cbox\Cms\Core\Tests\Postgres\Strictness\RecordingLogger;
use Cbox\Cms\Core\Tests\Postgres\Strictness\StrictnessChild;
use Cbox\Cms\Core\Tests\Postgres\Strictness\StrictnessParent;
use Cbox\Cms\Core\Tests\Postgres\Strictness\StrictnessTables;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;

/*
 * Eloquent's strictness, which CoreServiceProvider sets for every model of the process
 * (GUARDRAILS 4.1): a missing attribute and a silent assignment fail in every environment, and a
 * lazy load fails outside production and is logged as an error in production. The models are
 * test-only, on scratch tables, and the provider boots again in the environment a test names.
 */

/**
 * Boots the core's provider again in the environment given, as a process in that environment boots it.
 */
function bootCoreIn(string $environment): void
{
    app()->detectEnvironment(static fn (): string => $environment);
    $provider = app()->getProvider(CoreServiceProvider::class);

    if (! $provider instanceof CoreServiceProvider) {
        throw new RuntimeException('CoreServiceProvider is not registered.');
    }

    $provider->boot();
}

beforeEach(function (): void {
    StrictnessTables::create();
    StrictnessTables::seed();
});

afterEach(function (): void {
    StrictnessTables::drop();
    bootCoreIn('testing');
});

it('throws on reading an attribute the query did not load', function (string $environment): void {
    bootCoreIn($environment);

    $parent = StrictnessParent::query()->select(['id', 'name'])->findOrFail(1);

    expect($parent->name)->toBe('A')
        ->and(fn (): string => $parent->note)->toThrow(MissingAttributeException::class, 'The attribute [note] either does not exist or was not retrieved for model ['.StrictnessParent::class.'].');
})->with(['testing', 'production']);

it('throws on mass-assigning an attribute that is not fillable, and stores nothing', function (string $environment): void {
    bootCoreIn($environment);

    expect(fn (): StrictnessParent => StrictnessParent::query()->create(['id' => 3, 'name' => 'C', 'note' => 'dropped']))
        ->toThrow(MassAssignmentException::class, 'Add fillable property [id, note] to allow mass assignment on ['.StrictnessParent::class.'].')
        ->and(fn (): StrictnessParent => new StrictnessParent(['name' => 'C', 'note' => 'dropped']))
        ->toThrow(MassAssignmentException::class, 'Add fillable property [note] to allow mass assignment on ['.StrictnessParent::class.'].')
        ->and(StrictnessParent::query()->count())->toBe(2);

    $filled = new StrictnessParent(['name' => 'C']);

    expect($filled->name)->toBe('C');
})->with(['testing', 'production']);

it('throws on a lazy load outside production', function (string $environment): void {
    bootCoreIn($environment);
    $logger = new RecordingLogger;
    app()->instance(LoggerInterface::class, $logger);

    $parents = StrictnessParent::query()->orderBy('id')->get();

    expect(fn (): mixed => $parents->firstOrFail()->children)
        ->toThrow(LazyLoadingViolationException::class, 'Attempted to lazy load [children] on model ['.StrictnessParent::class.'] but lazy loading is disabled.')
        ->and($logger->records)->toBe([]);
})->with(['testing', 'local', 'staging']);

it('logs a lazy load as an error in production without throwing, and loads the relation', function (): void {
    bootCoreIn('production');
    $logger = new RecordingLogger;
    app()->instance(LoggerInterface::class, $logger);

    $parents = StrictnessParent::query()->orderBy('id')->get();
    $children = $parents->firstOrFail()->children;

    expect($children->map(static fn (StrictnessChild $child): string => $child->label)->all())->toBe(['A1', 'A2'])
        ->and($logger->records)->toBe([[
            LogLevel::ERROR,
            'Attempted to lazy load [children] on model ['.StrictnessParent::class.'] but lazy loading is disabled.',
            ['model' => StrictnessParent::class, 'relation' => 'children'],
        ]]);
});

it('loads eagerly loaded relations without a violation in every environment', function (string $environment): void {
    bootCoreIn($environment);
    $logger = new RecordingLogger;
    app()->instance(LoggerInterface::class, $logger);

    $labels = StrictnessParent::query()->with('children')->orderBy('id')->get()
        ->map(static fn (StrictnessParent $parent): array => $parent->children->map(static fn (StrictnessChild $child): string => $child->label)->all())
        ->all();

    expect($labels)->toBe([['A1', 'A2'], ['B1', 'B2']])
        ->and($logger->records)->toBe([]);
})->with(['testing', 'production']);

it('makes Eloquent strict when the application boots, before any test boots it again', function (): void {
    $parent = StrictnessParent::query()->select(['id'])->findOrFail(2);

    expect(fn (): string => $parent->name)->toThrow(MissingAttributeException::class)
        ->and(fn (): StrictnessParent => new StrictnessParent(['note' => 'x']))->toThrow(MassAssignmentException::class)
        ->and(fn (): mixed => StrictnessParent::query()->orderBy('id')->get()->firstOrFail()->children)->toThrow(LazyLoadingViolationException::class);
});

it('clears violation handlers that were set before it boots, so every violation throws outside production', function (): void {
    Model::handleMissingAttributeViolationUsing(static fn (): null => null);
    Model::handleDiscardedAttributeViolationUsing(static fn (): null => null);
    Model::handleLazyLoadingViolationUsing(static fn (): null => null);

    bootCoreIn('testing');

    expect(fn (): string => StrictnessParent::query()->select(['id'])->findOrFail(1)->name)->toThrow(MissingAttributeException::class)
        ->and(fn (): StrictnessParent => new StrictnessParent(['note' => 'x']))->toThrow(MassAssignmentException::class)
        ->and(fn (): mixed => StrictnessParent::query()->orderBy('id')->get()->firstOrFail()->children)->toThrow(LazyLoadingViolationException::class);
});
