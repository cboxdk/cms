<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\ReadModels;

use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Operations\Domain\ChunkName;
use Cbox\Cms\Core\Operations\Domain\Dto\OperationProgress;
use Cbox\Cms\Core\Operations\Domain\InvalidOperation;
use Cbox\Cms\Core\Operations\Domain\OperationId;
use Cbox\Cms\Core\Operations\Domain\OperationKey;
use Cbox\Cms\Core\Operations\Domain\OperationKind;
use Cbox\Cms\Core\Operations\Domain\OperationState;
use Cbox\Cms\Core\ReadModels\Boundary\RebuildConfig;
use Cbox\Cms\Core\ReadModels\Domain\Dto\ChunkResult;
use Cbox\Cms\Core\ReadModels\Domain\Dto\RebuildReport;
use Cbox\Cms\Core\ReadModels\Domain\Dto\RebuildRequest;
use Cbox\Cms\Core\ReadModels\Domain\Dto\RebuildSettings;
use Cbox\Cms\Core\ReadModels\Domain\EntryRange;
use Cbox\Cms\Core\ReadModels\Domain\RebuildRefused;
use Cbox\Cms\Core\ReadModels\Domain\RebuildTally;
use Illuminate\Config\Repository;
use InvalidArgumentException;

/*
 * The values of a rebuild (PRD 4.1, invariant 22): a range of entries and its chunk name, the
 * request and its operation key, the settings and their configuration, a chunk's result, the tally
 * and the report, and the catalog codes a rebuild refuses with.
 */

const REBUILD_ACTOR = '0192a0c0-0000-7000-8000-0000000000a1';

it('names a range as its chunk and reads it back', function (): void {
    $range = new EntryRange(RebuildWorld::entry(1), RebuildWorld::entry(2));
    $single = new EntryRange(RebuildWorld::entry(3), RebuildWorld::entry(3));

    expect($range->chunk()->value)->toBe('entries:'.RebuildWorld::entry(1)->toString().':'.RebuildWorld::entry(2)->toString())
        ->and(EntryRange::fromChunk($range->chunk()))->toEqual($range)
        ->and(EntryRange::fromChunk($single->chunk()))->toEqual($single);
});

it('refuses a range that runs backwards and a chunk that names no range', function (): void {
    expect(fn (): EntryRange => new EntryRange(RebuildWorld::entry(2), RebuildWorld::entry(1)))->toThrow(InvalidArgumentException::class, 'sorts after')
        ->and(fn (): EntryRange => EntryRange::fromChunk(new ChunkName('chunk-1')))->toThrow(InvalidArgumentException::class, 'is named entries:<first entry id>:<last entry id>')
        ->and(fn (): EntryRange => EntryRange::fromChunk(new ChunkName('entries:'.RebuildWorld::entry(1)->toString())))->toThrow(InvalidArgumentException::class)
        ->and(fn (): EntryRange => EntryRange::fromChunk(new ChunkName('entries:'.RebuildWorld::entry(2)->toString().':'.RebuildWorld::entry(1)->toString())))->toThrow(InvalidArgumentException::class, 'sorts after');
});

it('keys a request by its type and run, and refuses a run the key cannot hold', function (): void {
    expect(new RebuildRequest(new TypeName('app:news'), 'nightly')->key->value)->toBe('app:news@nightly')
        ->and(fn (): RebuildRequest => new RebuildRequest(new TypeName('app:news'), 'two words'))->toThrow(InvalidOperation::class)
        ->and(fn (): RebuildRequest => new RebuildRequest(new TypeName('app:news'), str_repeat('r', 200)))->toThrow(InvalidOperation::class);
});

it('reads the settings from cbox-cms.rebuild, with a chunk of 100 entries and no service actor by default', function (): void {
    $defaults = RebuildConfig::read(new Repository([]));
    $set = RebuildConfig::read(new Repository(['cbox-cms' => ['rebuild' => ['service_actor' => REBUILD_ACTOR, 'chunk_size' => 1000]]]));
    $empty = RebuildConfig::read(new Repository(['cbox-cms' => ['rebuild' => ['service_actor' => '', 'chunk_size' => 1]]]));

    expect([$defaults->serviceActor, $defaults->chunkSize])->toBe([null, 100])
        ->and($set->serviceActor?->toString())->toBe(REBUILD_ACTOR)
        ->and($set->chunkSize)->toBe(1000)
        ->and([$empty->serviceActor, $empty->chunkSize])->toBe([null, 1]);
});

it('refuses settings that are not of their type or outside their range', function (array $settings, string $message): void {
    expect(fn (): RebuildSettings => RebuildConfig::read(new Repository(['cbox-cms' => ['rebuild' => $settings]])))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'a chunk of none' => [['chunk_size' => 0], 'chunk_size must be a whole number from 1 to 1000'],
    'a chunk too large' => [['chunk_size' => 1001], 'chunk_size must be a whole number from 1 to 1000'],
    'a chunk as text' => [['chunk_size' => '100'], 'chunk_size must be a whole number'],
    'an actor that is no id' => [['service_actor' => 'rebuilder'], 'service_actor must be the UUIDv7 of a service actor, or null'],
    'an actor that is not text' => [['service_actor' => 7], 'service_actor must be the UUIDv7'],
]);

it('refuses settings with a chunk size outside 1 to 1000', function (): void {
    expect(fn (): RebuildSettings => new RebuildSettings(null, 0))->toThrow(InvalidArgumentException::class, 'holds 1 to 1000 entries')
        ->and(fn (): RebuildSettings => new RebuildSettings(null, 1001))->toThrow(InvalidArgumentException::class, 'got 1001');
});

it('refuses a chunk result with a negative count or time', function (int $entries, int $variants, int $milliseconds): void {
    expect(fn (): ChunkResult => new ChunkResult(new ChunkName('c'), $entries, $variants, $milliseconds))->toThrow(InvalidArgumentException::class, 'no negative count or time');
})->with([[-1, 0, 0], [0, -1, 0], [0, 0, -1]]);

it('reports the entries and the longest transaction of the chunks the tally kept, in order', function (): void {
    $tally = new RebuildTally;
    $tally->add(new ChunkResult(new ChunkName('one'), 50, 50, 120));
    $tally->add(new ChunkResult(new ChunkName('two'), 7, 9, 340));
    $progress = new OperationProgress(new OperationId('op_1'), new OperationKind('type_tables.rebuild'), new OperationKey('app:news@nightly'), OperationState::Completed, [new ChunkName('one'), new ChunkName('two')], []);

    $report = new RebuildReport(new TypeName('app:news'), ActorId::fromString(REBUILD_ACTOR), $progress, $tally->results());
    $none = new RebuildReport(new TypeName('app:news'), ActorId::fromString(REBUILD_ACTOR), $progress, []);

    expect(array_map(static fn (ChunkResult $chunk): string => $chunk->chunk->value, $report->chunks))->toBe(['one', 'two'])
        ->and($report->entries())->toBe(57)
        ->and($report->longestMilliseconds())->toBe(340)
        ->and([$none->entries(), $none->longestMilliseconds()])->toBe([0, 0]);
});

it('refuses with codes of the catalog', function (RebuildRefused $refused, string $code, ExitCode $exit): void {
    expect($refused->errorCode)->toBe($code)
        ->and(ErrorCode::from($refused->errorCode)->entry()->exit)->toBe($exit);
})->with([
    'an unknown type' => [RebuildRefused::unknownType(new TypeName('app:news')), 'rebuild_type_unknown', ExitCode::DataErr],
    'no service actor' => [RebuildRefused::notConfigured(), 'rebuild_identity_invalid', ExitCode::Config],
    'a payload at another version' => [RebuildRefused::schemaVersion(new TypeName('app:news'), RebuildWorld::entry(1), VariantKey::shared(), 1, 2), 'rebuild_schema_version_unsupported', ExitCode::DataErr],
]);
