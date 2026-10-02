<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Maintenance\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Envelope\IssuerKind as EnvelopeIssuer;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Access\Domain\AccessContexts;
use Cbox\Cms\Core\Maintenance\Domain\Dto\MaintenanceCall;
use Cbox\Cms\Core\Maintenance\Domain\InstallationOperator;
use Cbox\Cms\Core\Pipeline\Actions\CommandPipeline;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCall;

/**
 * Runs a maintenance command as the installation operator (PRD 5.16, 6.5 invariant 37), the work an
 * operator does from the console in a fresh installation, before any actor holds a grant: the
 * first site, the roles and the first staff member.
 *
 * It reads the operator through InstallationOperator; without one the call is rejected with
 * installation_operator_missing and nothing runs. It builds the envelope of the internal issuer
 * maintenance with the issuer kind system and the operator as the actor, whose idempotency key is
 * derived from the call's unit of work, so a rerun of the same work replays the first run's
 * receipt, and once the idempotency record has expired, plans nothing for an aggregate that exists.
 * The access context is the operator's, compiled from its grants as for any caller.
 *
 * The pipeline is the maintenance pipeline (CoreServiceProvider): the kernel's, with the
 * MaintenanceAuthorizer, which allows only its named commands, only to the operator and only on an
 * envelope of the maintenance issuer. Every other phase is the kernel's: the operator must be
 * active, and the actors a command names are held to invariant 37 as always.
 */
#[Internal]
final readonly class RunMaintenanceCommand
{
    public function __construct(
        private InstallationOperator $installation,
        private AccessContexts $contexts,
        private IdGenerator $ids,
        private CommandPipeline $pipeline,
    ) {}

    public function run(MaintenanceCall $call): WriteResult
    {
        $operator = $this->installation->find();

        if (! $operator instanceof ActorId) {
            return WriteResult::rejected(
                Receipt::rejected(WaitLevel::Commit, RetentionClass::Standard),
                new CatalogError(ErrorCode::InstallationOperatorMissing, null, 'The installation has no operator, so no maintenance command can run. Run cms:install in the maintenance process first.'),
            );
        }

        $access = $this->contexts->for(new ActorPrincipal($operator, [], IssuerKind::Service, IssuerKind::Service->maximumCeiling()));
        $envelope = Envelope::internal(
            IssuingSurface::Maintenance,
            EnvelopeIssuer::System,
            $operator,
            $call->unitOfWork,
            new CorrelationId($this->ids->next()->value),
        );

        return $this->pipeline->run(new CommandCall($call->command, $envelope, $access));
    }
}
