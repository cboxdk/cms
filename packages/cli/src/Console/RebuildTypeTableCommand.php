<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Console;

use Cbox\Cms\Cli\Boundary\RebuildArguments;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Core\ReadModels\Actions\RebuildReadModels;
use Cbox\Cms\Core\ReadModels\Domain\Dto\RebuildReport;
use Cbox\Cms\Core\ReadModels\Domain\Dto\RebuildRequest;
use Cbox\Cms\Core\ReadModels\Domain\RebuildRefused;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * `cms:types:rebuild`: rebuilds a type's read model, the rows of its type table, from the heads'
 * revisions or head snapshots (PRD 4.1, 11.6, invariant 22), as an operation in chunks of short
 * transactions (the action RebuildReadModels). It prints each chunk it ran and the operation's
 * state. A run that stopped, because a chunk failed or the process ended, is resumed at the chunk
 * that did not complete by running again with the same --run; a finished run does nothing again.
 *
 * Exit codes: 0 done, 64 invalid arguments or settings, 78 rebuild_identity_invalid,
 * 77 actor_not_active, 65 rebuild_type_unknown or rebuild_schema_version_unsupported.
 */
#[Internal]
#[Description('Rebuild the rows of a type\'s table from the heads\' revisions or head snapshots, in chunks')]
#[Signature('cms:types:rebuild
        {type : The type, as <owner>:<handle>}
        {--run= : The name of the run; run again with the same name to resume it}')]
final class RebuildTypeTableCommand extends Command
{
    public function handle(Container $container, Clock $clock, LoggerInterface $log): int
    {
        try {
            $request = RebuildArguments::request($this->argument('type'), $this->option('run'), $clock);
            $rebuild = $container->make(RebuildReadModels::class);
        } catch (InvalidArgumentException $invalid) {
            $this->error($invalid->getMessage());

            return ExitCode::Usage->value;
        }

        try {
            $report = $rebuild->rebuild($request);
        } catch (RebuildRefused $refused) {
            $log->error('The rebuild of a type\'s read model stopped.', ['code' => $refused->errorCode, 'type' => $request->type->value, 'key' => $request->key->value]);
            $this->error($refused->getMessage());

            if ($refused->errorCode === RebuildRefused::CODE_SCHEMA_VERSION) {
                $this->line(sprintf('The chunks before it stay rebuilt; run again with --run=%s to resume at the chunk that stopped.', $request->run));
            }

            return ErrorCode::from($refused->errorCode)->entry()->exit->value;
        }

        $this->report($request, $report, $log);

        return self::SUCCESS;
    }

    private function report(RebuildRequest $request, RebuildReport $report, LoggerInterface $log): void
    {
        foreach ($report->chunks as $chunk) {
            $this->line(sprintf('rebuilt %s: %d entries, %d variants in %d ms', $chunk->chunk->value, $chunk->entries, $chunk->variants, $chunk->milliseconds));
        }

        $this->info(sprintf(
            'The rebuild %s of %s as %s is %s: %d entries in %d chunks this run, %d of %d chunks completed.',
            $request->run,
            $report->type->value,
            $report->actor->toString(),
            $report->operation->state->value,
            $report->entries(),
            count($report->chunks),
            count($report->operation->completed),
            count($report->operation->completed) + count($report->operation->remaining),
        ));

        $log->info('A type\'s read model was rebuilt.', [
            'type' => $report->type->value,
            'key' => $request->key->value,
            'actor' => $report->actor->toString(),
            'state' => $report->operation->state->value,
            'entries' => $report->entries(),
            'chunks' => count($report->chunks),
            'longest_ms' => $report->longestMilliseconds(),
        ]);
    }
}
