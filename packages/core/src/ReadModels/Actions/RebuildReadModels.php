<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\ReadModels\Actions;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorDirectory;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Core\Access\Domain\AccessContexts;
use Cbox\Cms\Core\Operations\Domain\ChunkName;
use Cbox\Cms\Core\Operations\Domain\ChunkPlan;
use Cbox\Cms\Core\Operations\Domain\Dto\OperationProgress;
use Cbox\Cms\Core\Operations\Domain\Dto\OperationRequest;
use Cbox\Cms\Core\Operations\Domain\OperationInsideTransaction;
use Cbox\Cms\Core\Operations\Domain\OperationKind;
use Cbox\Cms\Core\Operations\Domain\OperationRunner;
use Cbox\Cms\Core\Operations\Domain\OperationState;
use Cbox\Cms\Core\ReadModels\Domain\Dto\RebuildReport;
use Cbox\Cms\Core\ReadModels\Domain\Dto\RebuildRequest;
use Cbox\Cms\Core\ReadModels\Domain\Dto\RebuildSettings;
use Cbox\Cms\Core\ReadModels\Domain\EntryRange;
use Cbox\Cms\Core\ReadModels\Domain\ReadModelStore;
use Cbox\Cms\Core\ReadModels\Domain\RebuildRefused;
use Cbox\Cms\Core\ReadModels\Domain\RebuildTally;

/**
 * Rebuilds a type's read model, the rows of its type table, from the heads (PRD 4.1, 11.6,
 * invariant 22): from the head's revisions for a type with full history, and from the head snapshot
 * for one whose history is audit-only or none, written with the writer the commands use. It is an
 * operation (GUARDRAILS 3, 4.2), `type_tables.rebuild` with the key `<type>@<run>`, run by the
 * OperationRunner from a console command, never in an HTTP request:
 *
 * 1. It reads the type from the TypeCatalog, or refuses with rebuild_type_unknown.
 * 2. It runs as the service actor of cbox-cms.rebuild.service_actor, never as the system (PRD 5.10,
 *    invariant 21): it refuses with rebuild_identity_invalid when none is configured, none exists
 *    or it is not a service actor, and with actor_not_active when it is not active. Its context
 *    comes from its grants, so it rebuilds the entries they reach.
 * 3. Unless the operation of the key is running or completed, it asks the ReadModelStore for the
 *    type's entries in ranges of chunkSize and makes each range a chunk.
 * 4. The runner starts the operation with that plan, resumes a running one at its first chunk that
 *    has not completed, or returns a completed one. Each chunk is one short transaction, and runs
 *    again safely, because it writes the rows its heads give whatever the table held.
 *
 * A chunk that finds a payload at another schema version throws RebuildRefused with
 * rebuild_schema_version_unsupported, which stops the run with the operation running at that
 * chunk; upcasters come with B3, so in M1 a rebuild reads the type's current version only.
 */
#[Experimental]
final readonly class RebuildReadModels
{
    public function __construct(
        private TypeCatalog $types,
        private ActorDirectory $actors,
        private AccessContexts $contexts,
        private ReadModelStore $store,
        private OperationRunner $runner,
        private RebuildSettings $settings,
    ) {}

    /**
     * @throws RebuildRefused when the type or the service actor cannot be used, or a chunk found a payload at another schema version
     * @throws OperationInsideTransaction when a transaction is open on the operations' connection
     */
    public function rebuild(RebuildRequest $request): RebuildReport
    {
        $type = $this->types->named($request->type) ?? throw RebuildRefused::unknownType($request->type);
        $actor = $this->identity();
        $access = $this->contexts->for(new ActorPrincipal($actor, [], IssuerKind::Service, ClassificationAccess::Sensitive));
        $existing = $this->runner->find(new OperationKind(RebuildChunks::KIND), $request->key);
        $plan = $existing instanceof OperationProgress && $existing->state !== OperationState::Failed
            ? null
            : new ChunkPlan(...array_map(
                static fn (EntryRange $range): ChunkName => $range->chunk(),
                $this->store->plan($type, $access, $this->settings->chunkSize),
            ));
        $tally = new RebuildTally;

        $progress = $this->runner->run(new OperationRequest(new RebuildChunks($type, $access, $plan, $this->store, $tally), $request->key));

        return new RebuildReport($type->name, $actor, $progress, $tally->results());
    }

    /**
     * The service actor the rebuild runs as.
     *
     * @throws RebuildRefused
     */
    private function identity(): ActorId
    {
        $id = $this->settings->serviceActor ?? throw RebuildRefused::notConfigured();
        $actor = $this->actors->find($id) ?? throw RebuildRefused::unknownActor($id);

        if ($actor->class !== ActorClass::Service) {
            throw RebuildRefused::notAService($id, $actor->class);
        }

        if (! $actor->isActive()) {
            throw RebuildRefused::notActive($id, $actor->state);
        }

        return $id;
    }
}
