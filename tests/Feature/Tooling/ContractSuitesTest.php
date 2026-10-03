<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling;

use Cbox\Cms\Core\Tests\Contract\SystemClockContractTest;
use Cbox\Cms\Core\Tests\Contract\SystemIdGeneratorContractTest;
use Cbox\Cms\Core\Tests\Contract\ValkeyFragmentStoreContractTest;
use Cbox\Cms\Core\Tests\Postgres\PostgresIdempotencyStoreContractTest;
use Cbox\Cms\Core\Tests\Postgres\PostgresReceiptStoreContractTest;
use Cbox\Cms\Testkit\Cache\FragmentStoreContract;
use Cbox\Cms\Testkit\Cdn\CdnDriverContract;
use Cbox\Cms\Testkit\Clock\ClockContract;
use Cbox\Cms\Testkit\Doctor\DoctorCheckContract;
use Cbox\Cms\Testkit\Idempotency\IdempotencyStoreContract;
use Cbox\Cms\Testkit\Ids\IdGeneratorContract;
use Cbox\Cms\Testkit\ReceiptStore\ReceiptStoreContract;
use Cbox\Cms\Testkit\Tests\Contract\FakeCdnDriverContractTest;
use Cbox\Cms\Testkit\Tests\Contract\FakeClockContractTest;
use Cbox\Cms\Testkit\Tests\Contract\FakeDoctorCheckContractTest;
use Cbox\Cms\Testkit\Tests\Contract\FakeFragmentStoreContractTest;
use Cbox\Cms\Testkit\Tests\Contract\FakeIdempotencyStoreContractTest;
use Cbox\Cms\Testkit\Tests\Contract\FakeIdGeneratorContractTest;
use Cbox\Cms\Testkit\Tests\Contract\FakeReceiptStoreContractTest;
use Cbox\Cms\Testkit\Tests\Contract\HardOnlyFakeCdnDriverContractTest;
use Cbox\Cms\Tests\Support\Phpstan;
use Examples\Contract\Clock\StagingClockContractTest;
use Examples\Contract\Doctor\UploadsDirectoryDoctorCheckContractTest;
use Examples\Contract\IdempotencyStore\CountingIdempotencyStoreContractTest;
use Examples\Contract\Ids\CountingIdGeneratorContractTest;
use Examples\Contract\ReceiptStore\ArrayReceiptStoreContractTest;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Process\Process;

/*
 * GUARDRAILS 2.3 and 9: the fake and the real adapter of a contract run the same shared suite.
 * These tests read what Pest will run in the Contract suite, so a shared case that one
 * implementation skips, or a contract test class that is moved out of the suite, fails here.
 */

/**
 * The test ids (Class::method) that Pest lists for a suite, the Contract suite by default, with
 * the given filter.
 *
 * @return list<string>
 */
function contractTests(string $filter, string $suite = 'Contract'): array
{
    $process = new Process(
        [PHP_BINARY, 'vendor/bin/pest', '--list-tests', '--colors=never', '--testsuite='.$suite, '--filter='.$filter],
        Phpstan::root(),
        null,
        null,
        120,
    );
    $process->mustRun();

    preg_match_all('/^ - (\S+?::\w+)/m', $process->getOutput(), $matches);

    return $matches[1];
}

/**
 * The names of the test methods a shared suite trait declares.
 *
 * @param  class-string  $trait
 * @return list<string>
 */
function sharedCases(string $trait): array
{
    $methods = array_filter(
        new ReflectionClass($trait)->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn (ReflectionMethod $method): bool => $method->getAttributes(Test::class) !== [],
    );

    return array_values(array_map(static fn (ReflectionMethod $method): string => $method->getName(), $methods));
}

it('runs every shared Clock case once for the SystemClock, the FakeClock and the documentation\'s StagingClock', function (): void {
    $cases = sharedCases(ClockContract::class);

    $expected = [];

    foreach ([SystemClockContractTest::class, FakeClockContractTest::class, StagingClockContractTest::class] as $class) {
        foreach ($cases as $case) {
            $expected[] = $class.'::'.$case;
        }
    }

    $listed = contractTests('Clock');
    sort($expected);
    sort($listed);

    expect($cases)->toContain('now_is_in_the_utc_time_zone', 'now_is_an_immutable_value', 'now_keeps_microseconds')
        ->and($listed)->toBe($expected);
});

it('runs every shared IdGenerator case once for the SystemIdGenerator, the FakeIdGenerator and the documentation\'s CountingIdGenerator', function (): void {
    $cases = sharedCases(IdGeneratorContract::class);

    $expected = [];

    foreach ([SystemIdGeneratorContractTest::class, FakeIdGeneratorContractTest::class, CountingIdGeneratorContractTest::class] as $class) {
        foreach ($cases as $case) {
            $expected[] = $class.'::'.$case;
        }
    }

    $listed = contractTests('IdGenerator');
    sort($expected);
    sort($listed);

    expect($cases)->toContain(
        'ids_have_version_7_and_the_rfc_9562_variant',
        'ids_made_while_time_stands_still_are_unique_and_sort_in_generation_order',
        'the_embedded_unix_milliseconds_are_the_current_time',
        'an_id_made_after_time_steps_back_one_second_sorts_after_the_one_before',
    )->and($listed)->toBe($expected);
});

it('runs every shared ReceiptStore case once for the FakeReceiptStore and once for the example store of the documentation', function (): void {
    $cases = sharedCases(ReceiptStoreContract::class);

    $expected = [];

    foreach ([FakeReceiptStoreContractTest::class, ArrayReceiptStoreContractTest::class] as $class) {
        foreach ($cases as $case) {
            $expected[] = $class.'::'.$case;
        }
    }

    $listed = contractTests('ReceiptStore');
    sort($expected);
    sort($listed);

    expect($cases)->toContain(
        'a_stored_receipt_is_found_equal',
        'mark_projection_updates_only_that_projection',
        'a_standard_receipt_expires_seven_days_after_its_changeset_time',
        'a_store_in_a_rolled_back_transaction_is_not_visible',
        'a_store_is_not_visible_to_another_session_until_commit',
    )->and($listed)->toBe($expected);
});

it('runs every shared ReceiptStore case once for the PostgresReceiptStore, in the Postgres suite', function (): void {
    $cases = sharedCases(ReceiptStoreContract::class);

    $expected = array_map(static fn (string $case): string => PostgresReceiptStoreContractTest::class.'::'.$case, $cases);

    $listed = contractTests('ReceiptStoreContract', 'Postgres');
    sort($expected);
    sort($listed);

    expect($cases)->toHaveCount(20)
        ->and($listed)->toBe($expected);
});

it('runs every shared IdempotencyStore case once for the FakeIdempotencyStore and once for the documented CountingIdempotencyStore example', function (): void {
    $cases = sharedCases(IdempotencyStoreContract::class);

    $expected = [];

    foreach ([FakeIdempotencyStoreContractTest::class, CountingIdempotencyStoreContractTest::class] as $class) {
        foreach ($cases as $case) {
            $expected[] = $class.'::'.$case;
        }
    }

    // The example's own case for what the decorator adds.
    $expected[] = CountingIdempotencyStoreContractTest::class.'::the_decorator_counts_every_claim_by_its_result';

    $listed = contractTests('IdempotencyStore');
    sort($expected);
    sort($listed);

    expect($cases)->toContain(
        'the_first_claim_on_a_key_is_fresh',
        'a_completed_and_committed_claim_replays_its_changeset',
        'the_same_key_with_another_content_hash_is_a_conflict',
        'a_claim_completed_in_a_rolled_back_transaction_leaves_the_key_fresh',
        'a_fresh_claim_committed_without_complete_leaves_the_key_fresh',
        'a_claim_held_by_an_open_transaction_is_in_flight_for_another_session',
        'a_claim_that_waits_while_the_holder_completes_and_commits_replays_its_changeset',
        'a_claim_with_another_content_hash_that_waits_while_the_holder_commits_is_a_conflict',
        'a_claim_that_waits_while_the_holder_rolls_back_is_fresh_and_holds_the_key',
        'a_claim_whose_holder_outlasts_the_budget_is_in_flight_only_after_the_whole_budget',
        'five_claims_with_the_same_key_and_hash_after_one_completed_commit_all_replay',
    )->and($listed)->toBe($expected);
});

it('runs every shared IdempotencyStore case once for the PostgresIdempotencyStore, in the Postgres suite', function (): void {
    $cases = sharedCases(IdempotencyStoreContract::class);

    $expected = array_map(static fn (string $case): string => PostgresIdempotencyStoreContractTest::class.'::'.$case, $cases);

    $listed = contractTests('IdempotencyStoreContract', 'Postgres');
    sort($expected);
    sort($listed);

    expect($cases)->toHaveCount(20)
        ->and($listed)->toBe($expected);
});

it('runs every shared DoctorCheck case once for the fake, once for each check of the core, the identity module and the panel module and once for the documented UploadsDirectoryCheck example', function (): void {
    $cases = sharedCases(DoctorCheckContract::class);
    $classes = [FakeDoctorCheckContractTest::class, UploadsDirectoryDoctorCheckContractTest::class];

    // Every core check but doctor.config, which only exists to fail and has no passing state.
    foreach (glob(Phpstan::root().'/packages/core/src/Doctor/Domain/Checks/*Check.php') ?: [] as $file) {
        $check = basename($file, 'Check.php');

        if ($check !== 'InvalidConfiguration') {
            $classes[] = 'Cbox\\Cms\\Core\\Tests\\Contract\\'.$check.'DoctorCheckContractTest';
        }
    }

    foreach (glob(Phpstan::root().'/packages/identity/src/Doctor/Domain/Checks/*Check.php') ?: [] as $file) {
        $classes[] = 'Cbox\\Cms\\Identity\\Tests\\Contract\\'.basename($file, 'Check.php').'DoctorCheckContractTest';
    }

    foreach (glob(Phpstan::root().'/packages/panel/src/Doctor/Domain/Checks/*Check.php') ?: [] as $file) {
        $classes[] = 'Cbox\\Cms\\Panel\\Tests\\Contract\\'.basename($file, 'Check.php').'DoctorCheckContractTest';
    }

    $expected = [];

    foreach ($classes as $class) {
        foreach ($cases as $case) {
            $expected[] = $class.'::'.$case;
        }
    }

    // The example's own case for its failure code.
    $expected[] = UploadsDirectoryDoctorCheckContractTest::class.'::a_missing_directory_is_a_violation_that_names_the_directory';

    $listed = contractTests('DoctorCheck');
    sort($expected);
    sort($listed);

    expect($classes)->toHaveCount(32)
        ->and($cases)->toContain(
            'a_passing_check_returns_a_pass_for_itself',
            'a_failing_check_returns_a_fail_with_its_kind_code_cause_and_fix',
            'running_a_check_again_gives_the_same_result',
        )->and($listed)->toBe($expected);
});

it('runs every shared FragmentStore case once for the FakeFragmentStore and once for the ValkeyFragmentStore, both in the Contract suite', function (): void {
    $cases = sharedCases(FragmentStoreContract::class);

    $expected = [];

    foreach ([FakeFragmentStoreContractTest::class, ValkeyFragmentStoreContractTest::class] as $class) {
        foreach ($cases as $case) {
            $expected[] = $class.'::'.$case;
        }
    }

    $listed = contractTests('FragmentStore');
    sort($expected);
    sort($listed);

    expect($cases)->toContain(
        'a_written_fragment_is_read_back_equal',
        'a_purge_removes_every_fragment_of_the_key_from_the_store_and_from_the_index_of_their_other_keys',
        'the_fence_refuses_a_fragment_built_at_or_below_the_purge_position_and_stores_one_built_above_it',
        'the_fence_compares_positions_by_their_numeric_value_up_to_the_largest_xid8',
        'a_fence_never_moves_down_and_keeps_the_later_end',
        'a_fence_ends_at_its_fence_until_on_the_time_source',
    )->and($listed)->toBe($expected);
});

it('runs every shared CdnDriver case once for the soft FakeCdnDriver and once for the hard-only one', function (): void {
    $cases = sharedCases(CdnDriverContract::class);

    $expected = [];

    foreach ([FakeCdnDriverContractTest::class, HardOnlyFakeCdnDriverContractTest::class] as $class) {
        foreach ($cases as $case) {
            $expected[] = $class.'::'.$case;
        }
    }

    $listed = contractTests('CdnDriver');
    sort($expected);
    sort($listed);

    expect($cases)->toContain(
        'a_purge_with_more_keys_than_one_request_takes_is_split_in_order',
        'a_soft_purge_is_applied_soft_only_by_a_driver_that_supports_it',
        'an_edge_that_refuses_makes_the_purge_throw_and_the_purge_succeeds_once_it_is_back',
    )->and($listed)->toBe($expected);
});
