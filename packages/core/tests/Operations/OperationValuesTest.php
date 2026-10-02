<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Operations;

use Cbox\Cms\Core\Operations\Domain\ChunkName;
use Cbox\Cms\Core\Operations\Domain\ChunkPlan;
use Cbox\Cms\Core\Operations\Domain\Dto\OperationProgress;
use Cbox\Cms\Core\Operations\Domain\InvalidOperation;
use Cbox\Cms\Core\Operations\Domain\OperationId;
use Cbox\Cms\Core\Operations\Domain\OperationInsideTransaction;
use Cbox\Cms\Core\Operations\Domain\OperationKey;
use Cbox\Cms\Core\Operations\Domain\OperationKind;
use Cbox\Cms\Core\Operations\Domain\OperationState;

/*
 * The values that name an operation and its chunks, and the refusals of the runner's domain.
 */

it('takes a kind of lower-case dotted segments up to the maximum length', function (string $kind): void {
    expect(new OperationKind($kind)->value)->toBe($kind);
})->with([
    'one segment' => ['rebuild'],
    'dotted' => ['entries.rebuild'],
    'digits and underscores' => ['seed_scale.v2'],
    'longest' => [str_repeat('a', OperationKind::MAX_LENGTH)],
]);

it('refuses a kind that is not lower-case dotted segments or is too long', function (string $kind): void {
    expect(static fn (): OperationKind => new OperationKind($kind))
        ->toThrow(InvalidOperation::class, sprintf('The operation kind "%s" is not valid', $kind));
})->with([
    'empty' => [''],
    'upper case' => ['Entries.rebuild'],
    'leading digit' => ['1rebuild'],
    'empty segment' => ['entries..rebuild'],
    'trailing dot' => ['entries.'],
    'separator of the lock text' => ['entries|rebuild'],
    'space' => ['entries rebuild'],
    'newline after' => ["rebuild\n"],
    'too long' => [str_repeat('a', OperationKind::MAX_LENGTH + 1)],
]);

it('takes keys and chunk names of visible ASCII up to their maximum length', function (): void {
    expect(new OperationKey('run-1:2026')->value)->toBe('run-1:2026')
        ->and(new OperationKey(str_repeat('k', OperationKey::MAX_LENGTH))->value)->toHaveLength(OperationKey::MAX_LENGTH)
        ->and(new ChunkName('rows-000001')->value)->toBe('rows-000001')
        ->and(new ChunkName(str_repeat('c', ChunkName::MAX_LENGTH))->value)->toHaveLength(ChunkName::MAX_LENGTH)
        ->and(new OperationId('op_01k')->value)->toBe('op_01k')
        ->and(new OperationId(str_repeat('i', 255))->value)->toHaveLength(255);
});

it('refuses keys, chunk names and ids that are empty, too long or not visible ASCII', function (string $value, int $longest): void {
    expect(static fn (): OperationKey => new OperationKey($value))->toThrow(InvalidOperation::class, sprintf('The operation key "%s" is not valid. Use 1 to %d', $value, OperationKey::MAX_LENGTH))
        ->and(static fn (): ChunkName => new ChunkName($value))->toThrow(InvalidOperation::class, sprintf('The chunk name "%s" is not valid. Use 1 to %d', $value, ChunkName::MAX_LENGTH));

    if ($longest === 0) {
        expect(static fn (): OperationId => new OperationId($value))->toThrow(InvalidOperation::class, sprintf('The operation id "%s" is not valid', $value));
    }
})->with([
    'empty' => ['', 0],
    'space' => ['a b', 0],
    'newline after' => ["a\n", 0],
    'not ASCII' => ['æ', 0],
    'too long' => [str_repeat('k', 201), 1],
]);

it('refuses an id longer than 255 characters', function (): void {
    expect(static fn (): OperationId => new OperationId(str_repeat('i', 256)))->toThrow(InvalidOperation::class);
});

it('compares the values exactly', function (): void {
    expect(new OperationKind('a.b')->equals(new OperationKind('a.b')))->toBeTrue()
        ->and(new OperationKind('a.b')->equals(new OperationKind('a.c')))->toBeFalse()
        ->and(new OperationKey('K')->equals(new OperationKey('K')))->toBeTrue()
        ->and(new OperationKey('K')->equals(new OperationKey('k')))->toBeFalse()
        ->and(new ChunkName('c1')->equals(new ChunkName('c1')))->toBeTrue()
        ->and(new ChunkName('c1')->equals(new ChunkName('c2')))->toBeFalse()
        ->and(new OperationId('op_1')->equals(new OperationId('op_1')))->toBeTrue()
        ->and(new OperationId('op_1')->equals(new OperationId('op_2')))->toBeFalse();
});

it('keeps a chunk plan in order and refuses a chunk named twice', function (): void {
    $plan = new ChunkPlan(new ChunkName('c2'), new ChunkName('c1'), new ChunkName('c3'));

    expect($plan->names())->toBe(['c2', 'c1', 'c3'])
        ->and(new ChunkPlan()->names())->toBe([])
        ->and(static fn (): ChunkPlan => new ChunkPlan(new ChunkName('c1'), new ChunkName('c2'), new ChunkName('c1')))
        ->toThrow(InvalidOperation::class, 'The chunk plan names the chunk "c1" twice.');
});

it('gives the names of the completed and the remaining chunks in order', function (): void {
    $progress = new OperationProgress(
        new OperationId('op_1'),
        new OperationKind('a.b'),
        new OperationKey('k'),
        OperationState::Running,
        [new ChunkName('c1'), new ChunkName('c2')],
        [new ChunkName('c3')],
    );

    expect($progress->completedNames())->toBe(['c1', 'c2'])
        ->and($progress->remainingNames())->toBe(['c3']);
});

it('names the transaction level in the refusal to run inside a transaction', function (): void {
    expect(OperationInsideTransaction::level(2)->getMessage())->toContain('transaction level 2')
        ->and(InvalidOperation::unreadable('op_1', 'a step has no name')->getMessage())->toBe('The stored operation "op_1" cannot be read: a step has no name.');
});

it('keeps the chunks of a plan as a list, also when they are given by name', function (): void {
    $chunks = ['first' => new ChunkName('chunk-1'), 'second' => new ChunkName('chunk-2')];

    expect(new ChunkPlan(...$chunks)->chunks)->toBe(array_values($chunks))
        ->and(new ChunkPlan(...$chunks)->names())->toBe(['chunk-1', 'chunk-2']);
});
