<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Postgres\WalkingSkeleton;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Tests\Entries\EntryFields;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;
use Cbox\Cms\Testkit\Postgres\IndependentConnections;

/*
 * PRD 6.1, 6.8, invariant 11, M1 point 3: two saves of one entry that run at the same time on
 * separate connections, each through the real command pipeline on Postgres, commit once. Each
 * save is an actor of its own on a connection of its own (IndependentConnections), with its own
 * command transaction. The first reads the variant at version 1; while its transaction is open,
 * after its read and before its commit, the second saves the same variant at version 1 and
 * commits. The first then locks the variant at commit, finds version 2 and fails with
 * version_conflict, so exactly one changeset is committed and the second save's content stands.
 * Two creates of one entry id end the same way: the second finds the entry and its variant, which
 * it read as absent, and each is a version_conflict.
 */

afterEach(function (): void {
    EntryWorld::cleanUp();
});

/**
 * @return list<string>
 */
function concurrentErrorCodes(WriteResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value, $result->errors);
}

function concurrentChangesets(string $command): int
{
    return StorageTables::superuser()->table('changesets')->where('command', $command)->count();
}

it('commits one of two concurrent revises of an entry and rejects the other with version_conflict', function (): void {
    EntryWorld::seed();
    [$first, $second] = app(IndependentConnections::class)->open(2);
    $one = new EntryWorld($first->getName(), seed: 1);
    $other = new EntryWorld($second->getName(), seed: 2);
    $type = EntryWorld::type(EntryWorld::ARTICLE);
    $one->create($type->id, EntryFields::article('Created'), 'create-concurrent');

    $winner = null;
    $one->meanwhile = static function () use ($other, &$winner): void {
        $winner = $other->revise(1, EntryFields::article('The second save'), 'revise-second');
    };
    $loser = $one->revise(1, EntryFields::article('The first save'), 'revise-first');

    expect($winner)->toBeInstanceOf(WriteResult::class)
        ->and($winner?->outcome())->toBe(Outcome::Committed)
        ->and($loser->outcome())->toBe(Outcome::Rejected)
        ->and(concurrentErrorCodes($loser))->toBe(['version_conflict'])
        ->and($loser->errors[0]->message)->toContain('variant:'.EntryWorld::ENTRY.':shared')
        ->and(concurrentChangesets('entry.revise'))->toBe(1)
        ->and(StorageTables::superuser()->table('variant_heads')->where('entry_id', EntryWorld::ENTRY)->value('version'))->toBe(2)
        ->and(StorageTables::superuser()->table('revisions')->where('entry_id', EntryWorld::ENTRY)->orderBy('rev_no')->pluck('rev_no')->all())->toBe([1, 2])
        ->and(StorageTables::superuser()->table('app__fixture_article')->where('cms_entry_id', EntryWorld::ENTRY)->pluck('fixture_title')->all())->toBe(['The second save'])
        ->and(StorageTables::superuser()->table('events')->where('type', 'variant.revised')->count())->toBe(2);
});

it('commits one of two concurrent creates of one entry id and rejects the other with version_conflict', function (): void {
    EntryWorld::seed();
    [$first, $second] = app(IndependentConnections::class)->open(2);
    $one = new EntryWorld($first->getName(), seed: 1);
    $other = new EntryWorld($second->getName(), seed: 2);
    $type = EntryWorld::type(EntryWorld::ARTICLE);

    $winner = null;
    $one->meanwhile = static function () use ($other, $type, &$winner): void {
        $winner = $other->create($type->id, EntryFields::article('The second create'), 'create-second');
    };
    $loser = $one->create($type->id, EntryFields::article('The first create'), 'create-first');

    expect($winner?->outcome())->toBe(Outcome::Committed)
        ->and($loser->outcome())->toBe(Outcome::Rejected)
        ->and(concurrentErrorCodes($loser))->toBe(['version_conflict', 'version_conflict'])
        ->and(array_map(static fn (CatalogError $error): bool => str_contains($error->message, 'no aggregate'), $loser->errors))->toBe([true, true])
        ->and(concurrentChangesets('entry.create'))->toBe(1)
        ->and(StorageTables::superuser()->table('entries')->count())->toBe(1)
        ->and(StorageTables::superuser()->table('app__fixture_article')->pluck('fixture_title')->all())->toBe(['The second create']);
});
