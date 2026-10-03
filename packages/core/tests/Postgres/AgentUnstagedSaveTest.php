<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Fields\DateTimeValue;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Tests\Entries\EntryFields;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use Cbox\Cms\Core\Tests\Placements\PlacementStructure;
use Cbox\Cms\Core\Tests\Placements\PlacementWorld;
use Cbox\Cms\Core\Tests\Publishing\PublishingWorld;
use DateTimeImmutable;
use PHPUnit\Framework\AssertionFailedError;

/*
 * An agent never changes what the public reads (invariant 18; security review S-4), through the
 * real command pipeline on Postgres with the kernel's CommandAuthorizer, on the workbench's
 * app:fixture_event, which has stages none and is routable: a save of it writes the released row
 * the public reads wherever a placement is open. With its placement live, or scheduled to open, an
 * agent that may run entry.revise on its home is refused with agent_visibility_forbidden and
 * nothing is written, while the person the world's actor is may revise it. With its placement
 * hidden the agent's save commits, its placement locked as read.
 */

afterEach(function (): void {
    EntryWorld::cleanUp();
});

const AGENT_UNSTAGED_TYPE = 'app:fixture_event';

const AGENT_UNSTAGED_ENTRY = '0192a0c0-0000-7000-8000-0000000006e1';

const AGENT_UNSTAGED_PLACEMENT = '0192a0c0-0000-7000-8000-0000000006c1';

/**
 * The event's required fields with the name given; nothing an agent may not set.
 */
function agentUnstagedFields(string $name): FieldValues
{
    return EntryFields::of([
        'fixture_name' => new TextValue($name),
        'fixture_starts_at' => new DateTimeValue(new DateTimeImmutable('2026-04-01T19:30:00.000000+00:00')),
        'fixture_kind' => new TextValue('fixture_concert'),
    ]);
}

function agentUnstagedCommitted(WriteResult ...$results): void
{
    foreach ($results as $result) {
        if ($result->outcome() !== Outcome::Committed) {
            throw new AssertionFailedError('A command did not commit: '.implode('; ', array_map(static fn (CatalogError $error): string => $error->code->value.' '.$error->message, $result->errors)));
        }
    }
}

/**
 * The world, granted the publisher's commands on both sites' roots, with the event created by its
 * person below north's section and placed there, hidden, at version 1 of its variant.
 *
 * @return array{PublishingWorld, PlacementStructure}
 */
function agentUnstagedWorld(): array
{
    $structure = PlacementWorld::seed();
    $world = new PublishingWorld([$structure->north->root, $structure->south->root], granted: true);
    agentUnstagedCommitted(
        $world->createEntry(EntryId::fromString(AGENT_UNSTAGED_ENTRY), AGENT_UNSTAGED_TYPE, agentUnstagedFields('Harbour songs'), $structure->northSection, 'unstaged-create'),
        $world->place(PlacementId::fromString(AGENT_UNSTAGED_PLACEMENT), EntryId::fromString(AGENT_UNSTAGED_ENTRY), $structure->northSection, $structure->north, 'harbour-concert', 'unstaged-place'),
    );

    return [$world, $structure];
}

/**
 * The event's names in its type table, by stage, and the number of entry.revise changesets.
 *
 * @return array{list<string>, int}
 */
function agentUnstagedState(): array
{
    $superuser = StorageTables::superuser();
    $names = StorageTables::texts($superuser, "select cms_stage || ' ' || fixture_name as value from app__fixture_event where cms_entry_id = ?::uuid order by cms_stage", [AGENT_UNSTAGED_ENTRY]);

    return [$names, $superuser->table('changesets')->where('command', 'entry.revise')->count()];
}

it('refuses an agent\'s revise of a live stages-none entry with agent_visibility_forbidden and writes nothing, and lets a person revise it', function (?string $from): void {
    [$world] = agentUnstagedWorld();
    $window = $from === null ? null : new TimeWindow(new DateTimeImmutable($from));
    agentUnstagedCommitted($world->publish(EntryId::fromString(AGENT_UNSTAGED_ENTRY), 1, null, PlacementId::fromString(AGENT_UNSTAGED_PLACEMENT), 1, 'unstaged-publish', $window));

    $agent = $world->revise(EntryId::fromString(AGENT_UNSTAGED_ENTRY), 1, agentUnstagedFields('An agent\'s songs'), 'unstaged-agent', agent: true);

    expect(array_map(static fn (CatalogError $error): string => $error->code->value, $agent->errors))->toBe(['agent_visibility_forbidden'])
        ->and($agent->receipt->changesetId)->toBeNull()
        ->and(agentUnstagedState())->toBe([['released Harbour songs'], 0]);

    agentUnstagedCommitted($world->revise(EntryId::fromString(AGENT_UNSTAGED_ENTRY), 1, agentUnstagedFields('A person\'s songs'), 'unstaged-person'));

    expect(agentUnstagedState())->toBe([['released A person\'s songs'], 1]);
})->with([
    'live now' => [null],
    'scheduled to open' => ['2026-04-01T08:00:00Z'],
]);

it('lets an agent revise a stages-none entry whose placement is hidden, locking the placement it read', function (): void {
    [$world] = agentUnstagedWorld();

    agentUnstagedCommitted($world->revise(EntryId::fromString(AGENT_UNSTAGED_ENTRY), 1, agentUnstagedFields('An agent\'s songs'), 'unstaged-agent', agent: true));

    expect(agentUnstagedState())->toBe([['released An agent\'s songs'], 1]);
});
