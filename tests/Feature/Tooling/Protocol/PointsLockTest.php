<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Protocol;

use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Protocol\Domain\Dto\SchemaBinding;
use Cbox\Cms\Panel\Tests\Points\PanelPointFixtures;
use Cbox\Cms\Tooling\Protocol\Adapter\ProtocolGeneration;
use Cbox\Cms\Tooling\Protocol\Boundary\PointsLock;
use Cbox\Cms\Tooling\Protocol\Domain\Dto\PointSchema;
use Cbox\Cms\Tooling\Protocol\Domain\PanelPointSchemas;
use LogicException;

/*
 * The compatibility lock of the panel's stable points (PRD 13.4), points.lock.json: the schema of a
 * #[Stable] point may only gain an optional member. The panel's points and the fixture's stable
 * point are held to their committed locks here, so a narrowing change to a stable schema fails
 * this test, and an added optional member passes it (composer generate:protocol then records it,
 * and gate 6 holds the committed lock to the schemas). The cases after them change the contract of
 * the fixture's stable schema, notes.list.toolbar@1, and lock it against the committed fixture
 * lock.
 */

/**
 * The point schemas of the bindings, read below $root.
 *
 * @param  list<SchemaBinding>  $bindings
 * @return list<PointSchema>
 */
function lockedPoints(string $root, array $bindings): array
{
    /** @var list<GenerationProblem> $problems */
    $problems = [];
    $points = ProtocolGeneration::points($root, $bindings, $problems);

    return $problems === [] ? $points : throw GenerationFailed::with($problems);
}

function lockRead(string $path): string
{
    $contents = file_get_contents($path);

    return is_string($contents) ? $contents : throw new LogicException('Cannot read '.$path.'.');
}

/**
 * The fixture's stable schema with $change applied to its decoded JSON, locked against the
 * fixture's committed lock: the new lock's JSON, or the problems that refused it. The lock compares
 * contracts, so the changed schema need not match the props class, which composer generate:protocol
 * also holds it to.
 *
 * @param  callable(array<mixed>): array<mixed>  $change
 * @return array{?string, list<string>}
 */
function lockChanged(callable $change): array
{
    $points = [];

    foreach (lockedPoints(PanelPointFixtures::root(), PanelPointFixtures::bindings()) as $point) {
        if ($point->binding->schema === 'notes.list.toolbar.v1.json') {
            $schema = json_decode(lockRead(PanelPointFixtures::root().'/'.$point->binding->path()), true, 64, JSON_THROW_ON_ERROR);

            if (! is_array($schema)) {
                throw new LogicException('Not a schema: '.$point->binding->path());
            }

            $changed = json_encode($change($schema), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $point = new PointSchema($point->binding, $point->contract, $point->point, $point->stable, PointsLock::contract($changed, $point->binding->path()), $point->sample);
        }

        $points[] = $point;
    }

    try {
        return [PointsLock::file($points, lockRead(PanelPointFixtures::root().'/'.PanelPointFixtures::LOCK), PanelPointFixtures::LOCK)->contents, []];
    } catch (GenerationFailed $failed) {
        return [null, array_map(static fn (GenerationProblem $problem): string => $problem->message, $failed->problems)];
    }
}

/**
 * The schema with the value at the path of keys set.
 *
 * @param  array<mixed>  $schema
 * @param  non-empty-list<string>  $path
 * @return array<mixed>
 */
function lockSet(array $schema, array $path, mixed $value): array
{
    $key = array_shift($path);

    if ($path === []) {
        $schema[$key] = $value;

        return $schema;
    }

    $child = $schema[$key] ?? [];
    $child = is_array($child) ? $child : [];
    $schema[$key] = lockSet($child, $path, $value);

    return $schema;
}

/**
 * The schema without the value at the path of keys.
 *
 * @param  array<mixed>  $schema
 * @param  non-empty-list<string>  $path
 * @return array<mixed>
 */
function lockUnset(array $schema, array $path): array
{
    $key = array_shift($path);

    if ($path === []) {
        unset($schema[$key]);

        return $schema;
    }

    $child = $schema[$key] ?? [];
    $child = is_array($child) ? $child : [];
    $schema[$key] = lockUnset($child, $path);

    return $schema;
}

/**
 * @param  array<mixed>  $schema
 * @return array<mixed>
 */
function lockMember(array $schema, string $key, mixed $member): array
{
    $properties = $schema['properties'] ?? [];
    $properties = is_array($properties) ? $properties : [];
    $properties[$key] = $member;
    $schema['properties'] = $properties;

    return $schema;
}

it('holds the panel\'s stable points to their committed lock: no change but an added optional member', function (): void {
    $root = PanelPointFixtures::root();
    $lock = PointsLock::file(lockedPoints($root, PanelPointSchemas::all()), lockRead($root.'/'.PanelPointSchemas::LOCK), PanelPointSchemas::LOCK);

    expect($lock->path)->toBe(PanelPointSchemas::LOCK);
});

it('holds the fixture\'s stable point to its committed lock, which records it and not the experimental points', function (): void {
    $root = PanelPointFixtures::root();
    $committed = lockRead($root.'/'.PanelPointFixtures::LOCK);
    $points = lockedPoints($root, PanelPointFixtures::bindings());
    $lock = json_decode(PointsLock::file($points, $committed, PanelPointFixtures::LOCK)->contents, true, 64, JSON_THROW_ON_ERROR);

    expect(is_array($lock) ? array_keys(is_array($lock['points'] ?? null) ? $lock['points'] : []) : null)->toBe(['notes.list.toolbar@1']);
});

it('passes an added optional member and records it with a new hash', function (): void {
    [$lock, $problems] = lockChanged(static fn (array $schema): array => lockMember($schema, 'sort', ['description' => 'The order of the list.', 'type' => 'string', 'enum' => ['newest', 'oldest'], 'default' => 'newest']));

    expect($problems)->toBe([])
        ->and($lock)->not->toBeNull()
        ->and($lock)->not->toBe(lockRead(PanelPointFixtures::root().'/'.PanelPointFixtures::LOCK))
        ->and($lock)->toContain('"sort"');
});

it('passes a change to what only documents the schema and keeps the lock as it is', function (): void {
    [$lock, $problems] = lockChanged(static function (array $schema): array {
        $schema['description'] = 'Reworded.';
        $schema['title'] = 'Reworded';

        return $schema;
    });

    expect($problems)->toBe([])
        ->and($lock)->toBe(lockRead(PanelPointFixtures::root().'/'.PanelPointFixtures::LOCK));
});

it('refuses a narrowing change to a stable schema, naming the point, the place and the next version', function (callable $change, string $refusal): void {
    [$lock, $problems] = lockChanged($change);

    expect($lock)->toBeNull()
        ->and($problems)->not->toBe([])
        ->and($problems)->toContain('The stable panel point notes.list.toolbar@1 '.$refusal.'. Its props may only gain an optional member; give the change to its next version, notes.list.toolbar@2, and keep this one with a downcast from it.');
})->with([
    'a lower maxLength' => [static fn (array $schema): array => lockSet($schema, ['properties', 'filter', 'maxLength'], 50), 'changes #/properties/filter/maxLength from 100 to 50'],
    'a nullable member made not nullable' => [static fn (array $schema): array => lockSet($schema, ['properties', 'filter', 'type'], 'string'), 'changes #/properties/filter/type from ["string","null"] to "string"'],
    'a higher minimum' => [static fn (array $schema): array => lockSet($schema, ['properties', 'count', 'minimum'], 1), 'changes #/properties/count/minimum from 0 to 1'],
    'a removed member' => [static fn (array $schema): array => lockSet(lockUnset($schema, ['properties', 'filter']), ['required'], ['count']), 'removes the member at #/properties/filter'],
    'a renamed member' => [static fn (array $schema): array => lockSet(lockSet(lockUnset($schema, ['properties', 'count']), ['properties', 'total'], ['description' => 'The number of notes.', 'type' => 'integer', 'minimum' => 0]), ['required'], ['filter', 'total']), 'adds the required member at #/properties/total'],
    'an added required member' => [static fn (array $schema): array => lockSet(lockMember($schema, 'sort', ['description' => 'The order of the list.', 'type' => 'string']), ['required'], ['count', 'filter', 'sort']), 'adds the required member at #/properties/sort'],
]);

it('refuses a member made required, or one made optional, and a widening of an existing member', function (): void {
    $optional = PointsLock::changes('{"type":"object","properties":{"a":{"type":"integer"}},"required":["a"]}', '{"type":"object","properties":{"a":{"type":"integer"}},"required":[]}', 'lock');
    $required = PointsLock::changes('{"type":"object","properties":{"a":{"type":"integer"}},"required":[]}', '{"type":"object","properties":{"a":{"type":"integer"}},"required":["a"]}', 'lock');
    $widened = PointsLock::changes('{"type":"object","properties":{"a":{"enum":["x"]}}}', '{"type":"object","properties":{"a":{"enum":["x","y"]}}}', 'lock');

    expect($optional)->toBe(['makes optional the member at #/properties/a'])
        ->and($required)->toBe(['makes required the member at #/properties/a'])
        ->and($widened)->toBe(['changes #/properties/a/enum from ["x"] to ["x","y"]']);
});

it('compares members through the definitions they refer to, so a renamed definition is no change and a narrowed one is', function (): void {
    $before = '{"type":"object","properties":{"a":{"$ref":"#/$defs/id"}},"$defs":{"id":{"type":"string","maxLength":9}}}';
    $renamed = '{"type":"object","properties":{"a":{"$ref":"#/$defs/actor"}},"$defs":{"actor":{"type":"string","maxLength":9}}}';
    $narrowed = '{"type":"object","properties":{"a":{"$ref":"#/$defs/id"}},"$defs":{"id":{"type":"string","maxLength":8}}}';

    expect(PointsLock::changes(PointsLock::contract($before, 'a'), PointsLock::contract($renamed, 'b'), 'lock'))->toBe([])
        ->and(PointsLock::changes($before, $narrowed, 'lock'))->toBe(['changes #/properties/a/maxLength from 9 to 8']);
});

it('keeps a member named like a documenting keyword in the contract', function (): void {
    expect(PointsLock::contract('{"type":"object","description":"Props.","properties":{"description":{"type":"string","description":"A text."}}}', 'a'))
        ->toBe('{"properties":{"description":{"type":"string"}},"type":"object"}');
});

it('refuses a stable point that leaves the lock, and a lock whose contract does not have its hash', function (): void {
    $committed = lockRead(PanelPointFixtures::root().'/'.PanelPointFixtures::LOCK);
    $experimental = array_values(array_filter(lockedPoints(PanelPointFixtures::root(), PanelPointFixtures::bindings()), static fn (PointSchema $point): bool => ! $point->stable));

    expect(static fn (): mixed => PointsLock::file($experimental, $committed, PanelPointFixtures::LOCK))
        ->toThrow(GenerationFailed::class, 'The stable panel point notes.list.toolbar@1 in packages/panel/tests/Points/Fixtures/points.lock.json has no stable schema any more.')
        ->and(static fn (): mixed => PointsLock::file($experimental, str_replace('"minimum": 0', '"minimum": 1', $committed), PanelPointFixtures::LOCK))
        ->toThrow(GenerationFailed::class, 'does not have its sha256');
});
