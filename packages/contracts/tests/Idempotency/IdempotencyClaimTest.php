<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Idempotency;

use Cbox\Cms\Contracts\Idempotency\ClaimResult;
use Cbox\Cms\Contracts\Idempotency\ClaimToken;
use Cbox\Cms\Contracts\Idempotency\Conflict;
use Cbox\Cms\Contracts\Idempotency\ContentHash;
use Cbox\Cms\Contracts\Idempotency\Fresh;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\IdempotencyScope;
use Cbox\Cms\Contracts\Idempotency\InFlight;
use Cbox\Cms\Contracts\Idempotency\InvalidClaim;
use Cbox\Cms\Contracts\Idempotency\InvalidIdempotencyValue;
use Cbox\Cms\Contracts\Idempotency\Replay;
use Cbox\Cms\Contracts\Idempotency\WaitBudget;
use Cbox\Cms\Contracts\IdempotencyStore;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\Uuid7;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/*
 * The types around the IdempotencyStore contract (PRD 6.1): the wait budget, the claim token, the
 * four claim results, the protocol error and the shape of the contract itself.
 */

function claimToken(string $key = 'retry-me', string $content = 'A'): ClaimToken
{
    return new ClaimToken(IdempotencyScope::forActor('user:7', 'entry.release'), new IdempotencyKey($key), ContentHash::of($content));
}

it('takes a wait budget from 0 up to the 5 second limit of a command transaction', function (int $milliseconds): void {
    expect(WaitBudget::milliseconds($milliseconds)->milliseconds)->toBe($milliseconds);
})->with([0, 1, 250, 5000]);

it('rejects a negative wait budget or one past the transaction limit', function (int $milliseconds): void {
    expect(static fn (): WaitBudget => new WaitBudget($milliseconds))
        ->toThrow(InvalidIdempotencyValue::class, sprintf('A wait budget is 0 to 5000 milliseconds, the most a command transaction may take, got %d.', $milliseconds));
})->with([-1, 5001, PHP_INT_MAX, PHP_INT_MIN]);

it('has a budget that does not wait', function (): void {
    expect(WaitBudget::none()->milliseconds)->toBe(0)
        ->and(WaitBudget::MAX_MILLISECONDS)->toBe(5000);
});

it('compares claim tokens by scope, key and hash', function (): void {
    $token = claimToken();

    expect($token->equals(claimToken()))->toBeTrue()
        ->and($token->equals(claimToken(key: 'other')))->toBeFalse()
        ->and($token->equals(claimToken(content: 'B')))->toBeFalse()
        ->and($token->equals(new ClaimToken(IdempotencyScope::forSource('user:7', 'entry.release'), $token->key, $token->hash)))->toBeFalse()
        ->and($token->equals(new ClaimToken(IdempotencyScope::forActor('user:7', 'entry.publish'), $token->key, $token->hash)))->toBeFalse();
});

it('has exactly four claim results, each a final readonly class', function (): void {
    $results = array_values(array_filter(
        array_map(static fn (string $file): string => 'Cbox\\Cms\\Contracts\\Idempotency\\'.basename($file, '.php'), glob(__DIR__.'/../../src/Idempotency/*.php') ?: []),
        static fn (string $class): bool => class_exists($class) && is_subclass_of($class, ClaimResult::class),
    ));
    sort($results);

    expect($results)->toBe([Conflict::class, Fresh::class, InFlight::class, Replay::class]);

    foreach ($results as $result) {
        $reflection = new ReflectionClass($result);
        expect($reflection->isFinal() && $reflection->isReadOnly())->toBeTrue("{$result} is not final readonly.");
    }
});

it('carries what the caller needs in each result', function (): void {
    $token = claimToken();
    $changesetId = new ChangesetId(Uuid7::lowestAt(1_767_225_600_000));
    $conflict = new Conflict($token->scope, $token->key);
    $inFlight = new InFlight($token->scope, $token->key, WaitBudget::milliseconds(100));

    expect(new Fresh($token)->token)->toBe($token)
        ->and(new Replay($changesetId)->changesetId)->toBe($changesetId)
        ->and(Conflict::CODE)->toBe('idempotency_conflict')
        ->and($conflict->key)->toBe($token->key)
        ->and($inFlight->waited->milliseconds)->toBe(100);
});

it('explains a claim outside a transaction', function (): void {
    $invalid = InvalidClaim::outsideTransaction('complete');

    expect($invalid)->toBeInstanceOf(LogicException::class)
        ->and($invalid->getMessage())->toBe('IdempotencyStore::complete() runs inside the caller\'s command transaction, and the connection has none open. A claim lasts until that transaction ends.');
});

it('names the key and scope of a token the transaction does not hold', function (): void {
    expect(InvalidClaim::notHeld(claimToken())->getMessage())
        ->toBe('This transaction holds no fresh claim on key "retry-me" of actor "user:7" for command type entry.release. complete() takes the token of a Fresh claim made in the same transaction, while it is still open.')
        ->and(InvalidClaim::alreadyCompleted(claimToken())->getMessage())
        ->toBe('The claim on key "retry-me" of actor "user:7" for command type entry.release was already completed in this transaction. A claim records one changeset.');
});

it('explains a claim outside READ COMMITTED', function (): void {
    expect(InvalidClaim::isolationLevel('repeatable read')->getMessage())
        ->toBe('IdempotencyStore::claim() needs the command transaction at READ COMMITTED, and it is REPEATABLE READ. A statement after the claim must see a commit made while the claim waited.');
});

it('has claim and complete, and no release', function (): void {
    $contract = new ReflectionClass(IdempotencyStore::class);
    $methods = array_map(static fn (ReflectionMethod $method): string => $method->getName(), $contract->getMethods());

    $signature = static function (string $method) use ($contract): array {
        $reflection = $contract->getMethod($method);
        $types = array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getType() instanceof ReflectionNamedType ? $parameter->getType()->getName() : '?',
            $reflection->getParameters(),
        );
        $return = $reflection->getReturnType();

        return [$types, $return instanceof ReflectionNamedType ? $return->getName() : '?'];
    };

    expect($contract->isInterface())->toBeTrue()
        ->and($methods)->toBe(['claim', 'complete'])
        ->and($signature('claim'))->toBe([[IdempotencyScope::class, IdempotencyKey::class, ContentHash::class, WaitBudget::class], ClaimResult::class])
        ->and($signature('complete'))->toBe([[ClaimToken::class, ChangesetId::class], 'void']);
});
