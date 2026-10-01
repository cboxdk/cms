<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Envelope\IssuerKind;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Envelope\UnitOfWork;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Core\Operations\Domain\ChunkedAction;
use Cbox\Cms\Core\Operations\Domain\ChunkName;
use Cbox\Cms\Core\Operations\Domain\ChunkPlan;
use Cbox\Cms\Core\Operations\Domain\InvalidOperation;
use Cbox\Cms\Core\Operations\Domain\OperationKind;
use Cbox\Cms\Core\Pipeline\Actions\CommandPipeline;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCall;
use Cbox\Cms\Core\Seeding\Domain\Commands\SeedEntries;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeededEntry;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedRequest;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedScope;
use Cbox\Cms\Core\Seeding\Domain\EntryGenerator;
use Cbox\Cms\Core\Seeding\Domain\SeedRefused;
use Cbox\Cms\Core\Seeding\Domain\SeedTargets;
use Override;

/**
 * The chunks of one seed run as an operation (GUARDRAILS 4.2, 4.3): one chunk per profile chunk of
 * entries, each run through the command pipeline as seed.entries, one composed plan and one
 * changeset, by the internal issuer seed as the run's service actor. The events go to the bulk
 * stream, and the changeset, its events and its audit happen at the Clock's time.
 *
 * A chunk is idempotent: its idempotency key is derived from its unit of work (the profile, the
 * seed, the chunk and its size, SeedRequest::unitOf()), so a chunk that committed and runs again,
 * because the process died before the operation recorded it, replays its first receipt. A chunk
 * whose entries all exist already is done without a command: its idempotency record may have
 * expired (7 days, or its partition dropped), or a run of the same seed with more entries reaches
 * the ids an earlier run wrote, and seed.entries would plan nothing, which the kernel rejects. The
 * entries are read through SeedTargets under the run's context, as seed.entries reads them. A chunk
 * the kernel rejects throws SeedRefused and leaves the operation running at that chunk.
 */
#[Internal]
final readonly class SeedChunks implements ChunkedAction
{
    public const string KIND = 'cms.seed';

    public const string CHUNK_PREFIX = 'chunk-';

    public function __construct(
        private SeedRequest $request,
        private SeedScope $scope,
        private CommandPipeline $pipeline,
        private SeedTargets $targets,
    ) {}

    #[Override]
    public function kind(): OperationKind
    {
        return new OperationKind(self::KIND);
    }

    #[Override]
    public function chunks(): ChunkPlan
    {
        $names = [];

        for ($chunk = 0, $chunks = $this->request->chunks(); $chunk < $chunks; $chunk++) {
            $names[] = new ChunkName(self::CHUNK_PREFIX.$chunk);
        }

        return new ChunkPlan(...$names);
    }

    /**
     * @throws SeedRefused when the kernel rejects the chunk
     * @throws InvalidOperation for a chunk name this run did not plan
     */
    #[Override]
    public function runChunk(ChunkName $chunk): void
    {
        $index = $this->index($chunk);
        $command = $this->command($index);

        if ($this->seeded($command)) {
            return;
        }

        $unit = $this->request->unitOf($index);
        $envelope = Envelope::internal(
            IssuingSurface::Seed,
            IssuerKind::Seed,
            $this->scope->actor,
            new UnitOfWork($unit),
            new CorrelationId(sprintf('seed:%s:%d', $this->request->profile->label(), $this->request->seed)),
        );

        $result = $this->pipeline->run(new CommandCall($command, $envelope, $this->scope->access));

        if (! $result->outcome()->isCommitted()) {
            $errors = $result->errors;
            $first = array_shift($errors) ?? new CatalogError(ErrorCode::ValidationFailed, null, 'The kernel committed nothing.');

            throw SeedRefused::rejected($unit, $first, ...$errors);
        }
    }

    /**
     * The command of the chunk: its entries from the run's generator.
     */
    public function command(int $chunk): SeedEntries
    {
        $generator = new EntryGenerator($this->request->profile, $this->request->seed, $this->scope->catalog->types, $this->scope->nodes, $this->scope->access->classificationAccess);
        $first = $this->request->firstOf($chunk);
        $entries = [];

        for ($index = $first, $last = $first + $this->request->sizeOf($chunk); $index < $last; $index++) {
            $entries[] = $generator->entry($index);
        }

        return new SeedEntries(...$entries);
    }

    /**
     * Whether every entry of the chunk exists already.
     */
    private function seeded(SeedEntries $command): bool
    {
        $entries = array_map(static fn (SeededEntry $entry): EntryId => $entry->entry, $command->entries);

        return count($this->targets->existing($this->scope->access, $entries)) === count($entries);
    }

    private function index(ChunkName $chunk): int
    {
        $digits = substr($chunk->value, strlen(self::CHUNK_PREFIX));

        if (! str_starts_with($chunk->value, self::CHUNK_PREFIX) || preg_match('/\A(?:0|[1-9][0-9]*)\z/', $digits) !== 1 || (int) $digits >= $this->request->chunks()) {
            throw InvalidOperation::chunk($chunk->value);
        }

        return (int) $digits;
    }
}
