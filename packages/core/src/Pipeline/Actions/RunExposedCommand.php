<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Envelope\RequestEnvelope;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\CredentialRejected;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Access\Domain\AccessContexts;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCall;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ExposedCall;

/**
 * Runs a write that arrived through an exposed surface (GUARDRAILS 2.1, PRD 6.1, 6.2): the part
 * that REST, the Inertia profile, MCP and the CLI share, between reading the transport and
 * translating the typed result for it.
 *
 * 1. The credential is verified with the CredentialVerifier. A refused credential rejects the call
 *    with the verifier's code, and a call without one, the anonymous principal, is unauthorized:
 *    every write through an exposed surface is made by an actor.
 * 2. A surface that requires an agent, MCP (Surface::requiresAgent()), takes only a credential
 *    issued for an agent: any other is unauthorized (PRD 2.31, 22).
 * 3. The on-behalf-of chain is the credential's. A caller may repeat it in the envelope; a chain
 *    that differs is unauthorized, because the chain comes from authentication, never from input.
 * 4. The AccessContext of the principal comes from AccessContexts.
 * 5. The command is read from its JSON document with its generated codec, as a caller with the
 *    context's classification access. A codec refuses a member of the command's own contract
 *    classified above that access; the fields of a revision, which a command carries in the generic
 *    form whose classification only the type knows, are held to the access by the command pipeline
 *    after plan() (WritableFields). A document the codec refuses rejects the call with
 *    json_malformed or json_invalid at the path of the value, relative to the command's document.
 * 6. The Envelope is built from the caller's fields with the surface, the actor, the issuer kind of
 *    the credential (IssuerKind::envelopeIssuer(): a person's session as human, an agent's
 *    credential as agent, a service's as system) and the caller's correlation id,
 *    or one from the IdGenerator when the caller sent none, and the command pipeline runs the call.
 *
 * A call rejected here commits nothing and claims no idempotency key; its receipt is rejected at
 * the wait level the caller asked for.
 */
#[Internal]
final readonly class RunExposedCommand
{
    public function __construct(
        private CredentialVerifier $credentials,
        private AccessContexts $contexts,
        private IdGenerator $ids,
        private CommandPipeline $pipeline,
    ) {}

    public function run(ExposedCall $call): WriteResult
    {
        $request = $call->envelope;

        try {
            $principal = $this->credentials->verify($call->credential);
        } catch (CredentialRejected $rejected) {
            return $this->rejected($request, ErrorCode::from($rejected->reason->value), null, $rejected->getMessage());
        }

        if (! $principal instanceof ActorPrincipal) {
            return $this->rejected($request, ErrorCode::Unauthorized, null, 'The call carries no credential. A command through an exposed surface is made by an actor; send the actor\'s credential.');
        }

        if ($call->surface->requiresAgent() && $principal->issuerKind !== IssuerKind::Agent) {
            return $this->rejected($request, ErrorCode::Unauthorized, null, sprintf('A call through the %s surface is made by an agent, and the credential was not issued for one. Send the credential of an agent, such as an MCP token.', $call->surface->value));
        }

        if ($request->onBehalfOf !== [] && ! $this->sameChain($request->onBehalfOf, $principal->onBehalfOf)) {
            return $this->rejected($request, ErrorCode::Unauthorized, null, 'The envelope names another on-behalf-of chain than the credential carries. The chain comes from the credential; leave it out of the envelope, or send the chain the credential carries.');
        }

        $access = $this->contexts->for($principal);

        try {
            $command = $call->codec->codec->decode($call->command, $access->classificationAccess);
        } catch (DecodingFailed $failed) {
            return $this->rejected($request, $failed->errorCode, $failed->path, $failed->reason);
        }

        $envelope = new RequestEnvelope(
            idempotencyKey: $request->idempotencyKey,
            correlationId: $request->correlationId,
            waitLevel: $request->waitLevel,
            dryRun: $request->dryRun,
            onBehalfOf: $principal->onBehalfOf,
            provenance: $request->provenance,
        )->envelope(
            IssuingSurface::of($call->surface),
            $principal->issuerKind->envelopeIssuer(),
            $principal->actor,
            new CorrelationId($this->ids->next()->value),
        );

        return $this->pipeline->run(new CommandCall($command, $envelope, $access));
    }

    private function rejected(RequestEnvelope $request, ErrorCode $code, ?FieldPath $path, string $message): WriteResult
    {
        return WriteResult::rejected(Receipt::rejected($request->waitLevel, RetentionClass::Standard), new CatalogError($code, $path, $message));
    }

    /**
     * @param  list<ActorId>  $one
     * @param  list<ActorId>  $other
     */
    private function sameChain(array $one, array $other): bool
    {
        return count($one) === count($other)
            && array_all($one, static fn (ActorId $actor, int $index): bool => $actor->equals($other[$index]));
    }
}
