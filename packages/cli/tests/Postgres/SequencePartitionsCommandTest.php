<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Postgres;

use Cbox\Cms\Core\Doctor\Domain\Checks\PartitionRunwayCheck;
use Cbox\Cms\Core\Doctor\Domain\Probes\PhpSettingsProbe;
use Cbox\Cms\Core\Tests\Doctor\Fakes\FakePhpSettingsProbe;
use Cbox\Cms\Core\Tests\Postgres\PartitionScratch;
use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use Illuminate\Contracts\Console\Kernel;
use UnexpectedValueException;

/*
 * A table partitioned on a bigint that a sequence feeds (PRD 4.1, 7.2), on real Postgres through
 * the commands an operator runs: when the sequence moves past the runway, the doctor's
 * partitions.runway fails, and cms:partitions:maintain creates the partitions ahead of the
 * sequence again, after which the check passes.
 */

beforeEach(function (): void {
    PartitionScratch::create();
    PartitionScratch::manage([PartitionScratch::SEQUENCE_TABLE => PartitionScratch::sequenced(100)], ['runway_partitions' => 2]);
    PartitionScratch::clockAt('2026-04-01T08:00:00Z');
});

afterEach(function (): void {
    app(IndependentConnections::class)->closeAll();
    PartitionScratch::drop();
});

/**
 * Runs cms:partitions:maintain and returns its exit code and output lines.
 *
 * @return array{int, list<string>}
 */
function maintainSequenced(): array
{
    $artisan = app(Kernel::class);
    $status = $artisan->call('cms:partitions:maintain');

    return [$status, array_values(array_filter(array_map(trim(...), explode("\n", $artisan->output())), static fn (string $line): bool => $line !== ''))];
}

/**
 * Runs cms:doctor --json in-process and returns partitions.runway as status, code and cause.
 *
 * @return array{mixed, mixed, mixed}
 */
function sequencedRunwayCheck(): array
{
    app()->instance(PhpSettingsProbe::class, new FakePhpSettingsProbe(allowUrlFopen: false));
    $artisan = app(Kernel::class);
    $artisan->call('cms:doctor', ['--json' => true]);
    $document = json_decode($artisan->output(), true, 512, JSON_THROW_ON_ERROR);

    foreach (is_array($document) && is_array($document['checks'] ?? null) ? $document['checks'] : [] as $check) {
        if (is_array($check) && ($check['id'] ?? null) === PartitionRunwayCheck::ID) {
            return [$check['status'] ?? null, $check['code'] ?? null, $check['cause'] ?? null];
        }
    }

    throw new UnexpectedValueException('The doctor reported no partitions.runway.');
}

it('fails the doctor once the sequence passes the runway, and cms:partitions:maintain creates the next partitions ahead of it', function (): void {
    expect(maintainSequenced())->toBe([0, [
        'created partition_scratch_seq.partition_scratch_seq_p0000000000000000000',
        'created partition_scratch_seq.partition_scratch_seq_p0000000000000000100',
        'created partition_scratch_seq.partition_scratch_seq_p0000000000000000200',
        'analyzed partition_scratch_seq',
        'runway partition_scratch_seq until id 300 (2 partitions ahead of id 0)',
        'Partitions maintained as role cms_owner: 3 changes.',
    ]])
        ->and(sequencedRunwayCheck())->toBe(['pass', null, null]);

    // Writes move the sequence past the last partition: the next id has no partition.
    PartitionScratch::advanceSequence(PartitionScratch::SEQUENCE, 450);

    expect(sequencedRunwayCheck())->toBe([
        'fail',
        PartitionRunwayCheck::CODE_SHORT,
        'At 2026-04-01T08:00:00Z: partition_scratch_seq has no partition for its sequence\'s current value 450.',
    ])
        ->and(maintainSequenced())->toBe([0, [
            'created partition_scratch_seq.partition_scratch_seq_p0000000000000000400',
            'created partition_scratch_seq.partition_scratch_seq_p0000000000000000500',
            'created partition_scratch_seq.partition_scratch_seq_p0000000000000000600',
            'analyzed partition_scratch_seq',
            'runway partition_scratch_seq until id 700 (2 partitions ahead of id 450)',
            'Partitions maintained as role cms_owner: 3 changes.',
        ]])
        ->and(sequencedRunwayCheck())->toBe(['pass', null, null]);

    PartitionScratch::app()->insert('insert into partition_scratch_seq (at) values (?)', ['2026-04-01 08:00:00+00']);

    expect(PartitionScratch::owner()->scalar('select id from partition_scratch_seq'))->toBe(451);
});
