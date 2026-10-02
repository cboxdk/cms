<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Reads\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\CredentialRejected;
use Cbox\Cms\Contracts\Identity\CredentialVerifier;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Contracts\Pipeline\ReadsContent;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Contracts\Results\ReadContent;
use Cbox\Cms\Core\Access\Domain\AccessResolver;
use Cbox\Cms\Core\Reads\Domain\Dto\AuditedRead;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryBinding;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Core\Reads\Domain\Dto\QuerySettings;
use Cbox\Cms\Core\Reads\Domain\Dto\ReadAuditRecord;
use Cbox\Cms\Core\Reads\Domain\InvalidQueryCall;
use Cbox\Cms\Core\Reads\Domain\QueryActions;
use Cbox\Cms\Core\Reads\Domain\QueryAuthorizer;
use Cbox\Cms\Core\Reads\Domain\QueryTransaction;
use Cbox\Cms\Core\Reads\Domain\ReadableFields;
use Cbox\Cms\Core\Reads\Domain\ReadAudit;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;

/**
 * The query pipeline (GUARDRAILS 2.1, PRD 6.2 "Læsninger"): the one way a read runs. It owns every
 * phase, and the action only states the cost of its query and reads. The whole call runs in one
 * QueryTransaction on the primary, which commits only an answered read:
 *
 * 1. Principal. The CredentialVerifier turns the credential the transport carried into the
 *    principal, or the anonymous principal when it carried none (invariant 25). A credential that is
 *    refused rejects the read with the code of its reason, and one of an actor that is not active,
 *    or that acts on behalf of one, with actor_not_active (PRD 5.16, invariant 37). The actor is
 *    never taken from the query or from anything else the caller sends. A read through a surface
 *    that requires an agent, MCP, is unauthorized without an agent's credential (PRD 2.31, 22).
 * 2. Context. The AccessResolver compiles the principal's grants into its AccessContext and sets it
 *    with SET LOCAL in the read transaction, so row level security holds for everything the read
 *    does (PRD 5.10), and the context ends with the transaction.
 * 3. Authorize, through the QueryAuthorizer with the context; a refusal is unauthorized.
 * 4. Budget. The action's cost() of the query, from the query alone, is checked against the
 *    principal's budget (QuerySettings); a read above it is query_over_budget, before it reads.
 * 5. Execute: the action's handle(), on the primary, where the transaction runs. Routing a read to a
 *    replica by a consistency token comes with the replicas (PRD 8.5).
 * 6. Strip. A result that ReadsContent gets its entries back with every field above the context's
 *    classification access left out, and every field their type does not declare (ReadableFields).
 *    For an agent's credential, every field its blueprint does not open to agents is left out too
 *    (PRD 2.31, 12.2): public and internal fields unless they say `agents: false`, confidential
 *    fields only with `agents: true`, and never a personal or sensitive field.
 * 7. Read audit. The fields of the stripped entries whose classification requires it are recorded
 *    through the ReadAudit in the same transaction, with the read's position, so the audit commits
 *    with the answer.
 * 8. Content keys and position. The answer carries the content keys of the entries, `e-{entry}` and
 *    `n-{node}` (PRD 9.4), the read's position, the xmin of its snapshot (PRD 8.4), the
 *    context's classification access, which a surface encodes the result with, and the issuer
 *    kind of the principal as a changeset records it: a person's session as human, an agent's
 *    credential as agent and a service's as system (IssuerKind::envelopeIssuer()).
 *
 * Every call gets a span named after its query and the metrics for its duration and its errors
 * through PipelineTelemetry, which runs the call, its transaction included (GUARDRAILS 5).
 *
 * The pipeline holds nothing between calls, and the actor context lives only in the transaction of
 * the read it was set for, so no context survives from one read to the next in a shared worker.
 * The pipeline never begins or ends a transaction; the QueryTransaction does.
 */
#[Internal]
final readonly class QueryPipeline
{
    public function __construct(
        private QueryActions $actions,
        private CredentialVerifier $credentials,
        private AccessResolver $access,
        private QueryAuthorizer $authorizer,
        private QuerySettings $settings,
        private ReadableFields $fields,
        private ReadAudit $audit,
        private QueryTransaction $transaction,
        private PipelineTelemetry $telemetry,
    ) {}

    public function run(QueryCall $call): QueryResult
    {
        $binding = $this->actions->for($call->query);

        return $this->telemetry->query($binding, $call, fn (): QueryResult => $this->transaction->run(fn (): QueryResult => $this->phases($call, $binding)));
    }

    private function phases(QueryCall $call, QueryBinding $binding): QueryResult
    {
        try {
            $principal = $this->credentials->verify($call->credential);
        } catch (CredentialRejected $rejected) {
            return QueryResult::rejected(new CatalogError(ErrorCode::from($rejected->reason->value), null, $rejected->getMessage()));
        }

        if ($call->surface?->requiresAgent() === true && ! $this->isAgent($principal)) {
            return QueryResult::rejected(new CatalogError(ErrorCode::Unauthorized, null, sprintf(
                'A read through the %s surface is made by an agent, and the call carries no credential issued for one. Send the credential of an agent, such as an MCP token.',
                $call->surface->value,
            )));
        }

        $access = $this->access->resolve($principal);
        $authorization = $this->authorizer->authorize($access, $binding->query, $call->query);

        if (! $authorization->allowed()) {
            return QueryResult::rejected(new CatalogError(ErrorCode::Unauthorized, null, (string) $authorization->reason));
        }

        $cost = $binding->action->cost($call->query);
        $budget = $this->settings->budgetOf($principal);

        if ($cost->exceeds($budget)) {
            return QueryResult::rejected(new CatalogError(ErrorCode::QueryOverBudget, null, sprintf(
                'The read %s costs %d, above the budget of %d for %s.',
                $binding->query->value,
                $cost->units,
                $budget->units,
                $principal instanceof ActorPrincipal ? 'an actor' : 'the anonymous principal',
            )));
        }

        $result = $binding->action->handle($call->query);
        $contents = [];

        if ($result instanceof ReadsContent) {
            $result = $this->stripped($result, $access, $this->isAgent($principal));
            $contents = $result->contents();
        }

        $position = $this->transaction->position();
        $this->audit($principal, $binding, $contents, $position);

        return QueryResult::answered(
            $result,
            $this->contentKeys($contents),
            $position,
            $access->classificationAccess,
            $principal instanceof ActorPrincipal ? $principal->issuerKind->envelopeIssuer() : null,
        );
    }

    /**
     * The result with every entry's fields stripped to the context's classification access, and for
     * an agent to the fields its blueprint opens to agents.
     */
    private function stripped(ReadsContent $result, AccessContext $access, bool $agent): ReadsContent
    {
        $contents = array_map(
            fn (ReadContent $content): ReadContent => $this->fields->strip($content, $access->classificationAccess, $agent),
            $result->contents(),
        );
        $stripped = $result->withContents($contents);

        if (! $this->sameContents($contents, $stripped->contents())) {
            throw InvalidQueryCall::contentsChanged($result::class);
        }

        return $stripped;
    }

    /**
     * Whether the principal is an actor whose credential was issued for an agent.
     */
    private function isAgent(Principal $principal): bool
    {
        return $principal instanceof ActorPrincipal && $principal->issuerKind === IssuerKind::Agent;
    }

    /**
     * The read audit of the fields that require it. Only an actor can read one: the anonymous
     * principal's classification access is public, and no public field requires the audit.
     *
     * @param  list<ReadContent>  $contents
     */
    private function audit(Principal $principal, QueryBinding $binding, array $contents, CommitPosition $position): void
    {
        if (! $principal instanceof ActorPrincipal) {
            return;
        }

        $reads = [];

        foreach ($contents as $content) {
            $audited = $this->fields->audited($content);

            if ($audited instanceof AuditedRead) {
                $key = $audited->entry->toString();
                $reads[$key] = isset($reads[$key])
                    ? new AuditedRead($audited->entry, [...$reads[$key]->fields, ...$audited->fields], $this->higher($reads[$key], $audited))
                    : $audited;
            }
        }

        if ($reads !== []) {
            $this->audit->record(new ReadAuditRecord($principal->actor, $binding->query, $binding->version, $position, array_values($reads)));
        }
    }

    private function higher(AuditedRead $one, AuditedRead $other): ClassificationAccess
    {
        return $one->classification->rank() < $other->classification->rank() ? $other->classification : $one->classification;
    }

    /**
     * @param  list<ReadContent>  $contents
     * @return list<DependencyKey>
     */
    private function contentKeys(array $contents): array
    {
        $keys = [];

        foreach ($contents as $content) {
            array_push($keys, ...$content->contentKeys());
        }

        return $keys;
    }

    /**
     * Whether the result holds exactly the entries it was given, fields included, so a result
     * cannot keep a field the pipeline stripped.
     *
     * @param  list<ReadContent>  $given
     * @param  list<ReadContent>  $held
     */
    private function sameContents(array $given, array $held): bool
    {
        return count($given) === count($held)
            && array_all($given, static fn (ReadContent $content, int $index): bool => $content->entry->equals($held[$index]->entry)
                && $content->node->equals($held[$index]->node)
                && $content->type->equals($held[$index]->type)
                && $content->fields->equals($held[$index]->fields));
    }
}
