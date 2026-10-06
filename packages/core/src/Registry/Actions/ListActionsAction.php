<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\NavContribution;
use Cbox\Cms\Contracts\PanelPoints\PageContribution;
use Cbox\Cms\Contracts\PanelPoints\PageName;
use Cbox\Cms\Contracts\Pipeline\ActorQuery;
use Cbox\Cms\Contracts\Pipeline\PublicQuery;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Core\Access\Domain\Dto\Grant;
use Cbox\Cms\Core\Access\Domain\Dto\HeldGrant;
use Cbox\Cms\Core\Access\Domain\OwnHeldGrants;
use Cbox\Cms\Core\Access\Domain\PermissionRule;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\ContractSummaries;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionList;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\ContractSummary;
use Cbox\Cms\Core\Registry\Domain\Dto\DisabledContributions;
use Cbox\Cms\Core\Registry\Domain\Dto\ListedAction;
use Cbox\Cms\Core\Registry\Domain\Dto\ListedNavEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelFill;
use Cbox\Cms\Core\Registry\Domain\InvalidPanelActivation;
use Cbox\Cms\Core\Registry\Domain\ListedActionKind;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\PanelActivation;
use Cbox\Cms\Core\Registry\Domain\Queries\ListActions;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Override;

/**
 * action.list (GUARDRAILS 8, PRD 13.2, 13.4): what the command palette is built from, decided on
 * the server so the palette never offers what the server would refuse.
 *
 * The actions: every action of the compiled registry exposed on Inertia, the surface the panel
 * runs commands and reads through, that the actor may run, in the registry's order. An action is
 * allowed as the query authorizer and the command authorizer allow it on some node: a PublicQuery
 * for anyone, an ActorQuery for every actor, and any other action through a role whose permissions
 * name it and that reaches some node in some locale, as the PermissionRule decides from the
 * actor's grants, read under the read's actor context (OwnHeldGrants). Each is listed with the
 * title and description of its contract version's JSON Schema (ContractSummaries); an action whose
 * contract no codec reads is left out, because no surface can run it. An action exposed on no
 * surface, or not on Inertia, is never listed, however the actor is placed.
 *
 * The navigation: every NavContribution of the panel registry that the activation state leaves
 * enabled, whose `requires` the actor holds the same way, and whose page the actor gets: for a
 * PageContribution of the registry, an addon's page, one that is enabled and whose `requires` the
 * actor holds; any other page is one of the panel's own, whose own permission the entry's scope
 * requires. The entries keep render order, the lowest priority first, then the addon's namespace,
 * then the contribution's id.
 *
 * The context names the actor alone (ActorContext), so for a credential issued on behalf of
 * others the list is the actor's own; the chain's grants are decided when a command or read runs
 * (PRD 5.16), and the panel's session credentials, the viewers of the palette in part 1 of B1, act
 * on behalf of no one. It costs one unit: the registry from its cache and the actor's grants in
 * two statements.
 *
 * @implements QueryAction<ListActions, ActionList>
 */
#[Action(handles: ListActions::class, surfaces: [Surface::Rest, Surface::Inertia])]
#[Internal]
final readonly class ListActionsAction implements QueryAction
{
    /** What the read costs: the registry from its cache and the actor's grants. */
    public const int COST = 1;

    public function __construct(
        private RegistryCache $registry,
        private PanelActivation $activation,
        private OwnHeldGrants $grants,
        private ContractSummaries $summaries,
        private PermissionRule $rule,
    ) {}

    #[Override]
    public function cost(Query $query): QueryCost
    {
        return new QueryCost(self::COST);
    }

    /**
     * @throws RegistryCacheMissing when cms:build has not written the registry
     * @throws MalformedRegistryCache when the registry cannot be read
     * @throws InvalidPanelActivation when the activation state cannot be read
     */
    #[Override]
    public function handle(Query $query): ActionList
    {
        $registry = $this->registry->read();
        $disabled = $this->activation->disabled();
        $held = $this->grants->held();

        return new ActionList($this->actions($registry, $held), $this->navigation($registry, $disabled, $held));
    }

    /**
     * @param  list<HeldGrant>  $held
     * @return list<ListedAction>
     */
    private function actions(CompiledRegistry $registry, array $held): array
    {
        $actions = [];

        foreach ($registry->actions as $action) {
            if (! $action->exposes(Surface::Inertia) || ! $this->allowed($action, $held)) {
                continue;
            }

            $summary = $this->summaries->of($action->kind, $action->command, $action->commandVersion);

            if (! $summary instanceof ContractSummary) {
                continue;
            }

            $actions[] = new ListedAction($action->command, $action->commandVersion, ListedActionKind::of($action->kind), $summary->title, $summary->description);
        }

        return $actions;
    }

    /**
     * @param  list<HeldGrant>  $held
     * @return list<ListedNavEntry>
     */
    private function navigation(CompiledRegistry $registry, DisabledContributions $disabled, array $held): array
    {
        $pages = [];
        $fills = [];

        foreach ($registry->panel as $point) {
            foreach ($disabled->apply($point)->fills as $fill) {
                if ($fill->declaration instanceof PageContribution) {
                    $pages[$fill->contribution->value] = $fill->enabled && $this->requiresHeld($fill, $held);
                }

                if ($fill->enabled && $fill->declaration instanceof NavContribution) {
                    $fills[] = $fill;
                }
            }
        }

        $navigation = [];

        foreach ($fills as $fill) {
            $declaration = $fill->declaration;

            if (! $declaration instanceof NavContribution || ! $this->requiresHeld($fill, $held) || ($pages[$declaration->page] ?? true) === false) {
                continue;
            }

            $navigation[] = new ListedNavEntry($fill->contribution, $declaration->label, $declaration->icon, new PageName($declaration->page));
        }

        return $navigation;
    }

    /**
     * Whether the actor may run the action somewhere: a public query for anyone, an actor query for
     * every actor, any other action through a role whose permissions name it.
     *
     * @param  list<HeldGrant>  $held
     */
    private function allowed(ActionEntry $action, array $held): bool
    {
        if ($action->kind === ActionKind::Query && (is_a($action->commandClass, PublicQuery::class, true) || is_a($action->commandClass, ActorQuery::class, true))) {
            return true;
        }

        return $this->holds($action->command, $held);
    }

    /**
     * @param  list<HeldGrant>  $held
     */
    private function requiresHeld(PanelFill $fill, array $held): bool
    {
        return ! $fill->scope->requires instanceof CommandName || $this->holds($fill->scope->requires, $held);
    }

    /**
     * Whether a role of the actor whose permissions name the command or read reaches some node in
     * some locale, as the PermissionRule decides it.
     *
     * @param  list<HeldGrant>  $held
     */
    private function holds(CommandName $permission, array $held): bool
    {
        $grants = array_values(array_map(
            static fn (HeldGrant $grant): Grant => $grant->grant,
            array_filter($held, static fn (HeldGrant $grant): bool => $grant->permits($permission)),
        ));

        return $this->rule->query($permission, $grants)->allowed();
    }
}
