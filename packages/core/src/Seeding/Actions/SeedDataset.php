<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorDirectory;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Validation\TypeValidators;
use Cbox\Cms\Core\Access\Domain\AccessContexts;
use Cbox\Cms\Core\Operations\Domain\Dto\OperationRequest;
use Cbox\Cms\Core\Operations\Domain\OperationKey;
use Cbox\Cms\Core\Operations\Domain\OperationRunner;
use Cbox\Cms\Core\Pipeline\Actions\CommandPipeline;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedReport;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedRequest;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedScope;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedSettings;
use Cbox\Cms\Core\Seeding\Domain\SeedRefused;
use Cbox\Cms\Core\Seeding\Domain\SeedTargets;
use Cbox\Cms\Core\Seeding\Domain\SeedTypes;

/**
 * Seeds a data set through the kernel (GUARDRAILS 4.3, PRD 23), the action behind cms:seed-scale:
 *
 * 1. The service actor cbox-cms.seeding.service_actor names must exist, be of class service and be
 *    active; its access context is computed from its grants, as for any caller.
 * 2. The seedable types are the catalog's types whose required fields the actor may write
 *    (SeedTypes), and the nodes are those its regions reach (SeedTargets); a run needs both.
 * 3. The chunks run as an operation (SeedChunks) through the OperationRunner, keyed by the profile,
 *    the seed and the entries: a run that stopped resumes at its first chunk that did not complete,
 *    and a completed run seeds nothing again.
 *
 * It runs outside any transaction, from the console, never in an HTTP request (GUARDRAILS 4.2). The
 * pipeline it runs is the seeder's own (CoreServiceProvider), which authorizes seed.entries only
 * (SeedAuthorizer) and hashes its chunks (SeedContentHasher).
 */
#[Internal]
final readonly class SeedDataset
{
    public function __construct(
        private SeedSettings $settings,
        private ActorDirectory $actors,
        private AccessContexts $contexts,
        private SeedTargets $targets,
        private TypeCatalog $types,
        private TypeValidators $validators,
        private OperationRunner $operations,
        private CommandPipeline $pipeline,
    ) {}

    /**
     * @throws SeedRefused when the service actor, its reach or the catalog leaves nothing to seed,
     *                     or when the kernel rejects a chunk
     */
    public function run(SeedRequest $request): SeedReport
    {
        $scope = $this->scope();
        $chunks = new SeedChunks($request, $scope, $this->pipeline, $this->targets);
        $progress = $this->operations->run(new OperationRequest($chunks, new OperationKey($request->operationKey())));

        return new SeedReport($request, $scope, $progress);
    }

    /**
     * Where and as whom the run writes.
     *
     * @throws SeedRefused
     */
    public function scope(): SeedScope
    {
        $actor = $this->actor();
        $access = $this->contexts->for(new ActorPrincipal($actor, [], IssuerKind::Service, IssuerKind::Service->maximumCeiling()));
        $catalog = SeedTypes::of($this->types->all(), $this->validators, $access->classificationAccess);

        if ($catalog->types === []) {
            throw SeedRefused::noTypes($catalog->skipped);
        }

        $nodes = $this->targets->nodes($access);

        if ($nodes === []) {
            throw SeedRefused::noNodes($actor);
        }

        return new SeedScope($actor, $access, $catalog, $nodes);
    }

    private function actor(): ActorId
    {
        $id = $this->settings->serviceActor ?? throw SeedRefused::notConfigured();
        $actor = $this->actors->find($id);

        if (! $actor instanceof Actor) {
            throw SeedRefused::unknown($id);
        }

        if ($actor->class !== ActorClass::Service) {
            throw SeedRefused::notAService($id, $actor->class);
        }

        if (! $actor->isActive()) {
            throw SeedRefused::notActive($id, $actor->state);
        }

        return $id;
    }
}
