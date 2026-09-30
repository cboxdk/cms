<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Console;

use Cbox\Cms\Cli\Boundary\SeedScaleOptions;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Core\Seeding\Actions\SeedDataset;
use Cbox\Cms\Core\Seeding\Boundary\SeedingConfig;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedableType;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedReport;
use Cbox\Cms\Core\Seeding\Domain\SeedRefused;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * `cms:seed-scale`: seeds a data set of --entries entries through the kernel (GUARDRAILS 4.3, PRD
 * 23), from the seed profile --profile (small for fixtures and tests, scale for the scale data set)
 * and the seed --seed, as the service actor cbox-cms.seeding.service_actor names (the action
 * SeedDataset). Each chunk is one changeset of seed.entries on the bulk stream, and the run is an
 * operation: a run that stopped resumes where it stopped when it is started again with the same
 * options, and a run that completed seeds nothing again. The same options give the same entries.
 *
 * It prints what it seeded and the wall time. Exit codes: 0 done, 64 invalid options, 78 a service
 * actor that is not configured, unknown or not a service actor, a setting that cannot be read, or a
 * catalog with no type the actor can seed, 77 an actor that is not active or reaches no node, 70 a
 * chunk the kernel rejected.
 */
#[Internal]
#[Description('Seed a data set of skewed entries of every type through the kernel, from a versioned profile and a fixed seed')]
#[Signature('cms:seed-scale
        {--entries= : How many entries the data set has}
        {--profile=small : The seed profile: small or scale}
        {--seed=1 : The seed; the same seed gives the same entries}')]
final class SeedScaleCommand extends Command
{
    public function handle(Container $container, Repository $config, LoggerInterface $log): int
    {
        try {
            $request = SeedScaleOptions::parse($this->option('profile'), $this->option('seed'), $this->option('entries'));
        } catch (InvalidArgumentException $invalid) {
            $this->error($invalid->getMessage());

            return ExitCode::Usage->value;
        }

        try {
            SeedingConfig::read($config);
        } catch (InvalidArgumentException $invalid) {
            $this->error($invalid->getMessage());

            return ExitCode::Config->value;
        }

        $started = hrtime(true);

        try {
            $report = $container->make(SeedDataset::class)->run($request);
        } catch (SeedRefused $refused) {
            $log->error('The seeder refused to seed.', ['profile' => $request->profile->label(), 'seed' => $request->seed, 'entries' => $request->entries]);
            $this->error($refused->getMessage());

            return $refused->exit->value;
        }

        $this->report($report, (hrtime(true) - $started) / 1e9);

        return self::SUCCESS;
    }

    private function report(SeedReport $report, float $seconds): void
    {
        $request = $report->request;

        foreach ($report->scope->catalog->skipped as $skipped) {
            $this->warn($skipped);
        }

        $this->info(sprintf(
            'Seeded %d entries of profile %s with seed %d in %d chunks of up to %d as %s, over %d nodes and the types %s: operation %s is %s.',
            $request->entries,
            $request->profile->label(),
            $request->seed,
            $request->chunks(),
            $request->profile->chunkSize,
            $report->scope->actor->toString(),
            count($report->scope->nodes),
            implode(', ', array_map(static fn (SeedableType $type): string => $type->definition->name->value, $report->scope->catalog->types)),
            $report->operation->id->value,
            $report->operation->state->value,
        ));
        $this->line(sprintf('Wall time: %.1f s.', $seconds));
    }
}
