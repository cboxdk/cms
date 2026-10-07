<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Access\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Core\Access\Domain\Queries\ListGrants;
use Cbox\Cms\Core\Access\Domain\Queries\ListRoles;
use Cbox\Cms\Core\Identity\Domain\Queries\ListActors;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Core\Reads\Domain\ListLimit;
use Cbox\Cms\Core\Routing\Boundary\SitesConfig;
use Cbox\Cms\Core\Structure\Domain\Queries\ListNodes;
use Cbox\Cms\Http\Inertia\Boundary\InertiaProps;
use Cbox\Cms\Panel\Access\Domain\AccessGrants;
use Cbox\Cms\Panel\Access\Domain\Dto\AccessGrantsSectionsV1;
use Cbox\Cms\Panel\Boundary\Generated\GrantPickersCodecV1;
use Cbox\Cms\Panel\Boundary\PanelReads;
use Cbox\Cms\Panel\Contributions\Boundary\ContributionProps;
use Cbox\Cms\Panel\Contributions\Boundary\SharedProps;
use Cbox\Cms\Panel\Contributions\Domain\Dto\PanelView;
use Cbox\Cms\Panel\Contributions\Domain\Dto\RenderedPoint;
use Cbox\Cms\Panel\Domain\Dto\GrantPickers;
use Cbox\Cms\Panel\Domain\Dto\PickerRead;
use Cbox\Cms\Panel\Domain\Dto\ReadAnswer;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Inertia\OptionalProp;
use LogicException;
use Throwable;

/**
 * A request of the grants page (PRD 5.10, 13.4), read for its controller: the view of the page,
 * which renders its sections point; the call of grant.list as the person who signed in, from the
 * session credential (PanelReads), for the page of grants after the id the address names
 * (ListCursor); the read's answer as the page shows it, the result's document or the problem
 * details of a rejection; the locales the form offers, those of the configured sites
 * (cbox-cms.sites); and the pickers of the form, the optional prop PICKERS, which Inertia resolves
 * only on a partial reload that asks for it, when the form opens: the reads of actor.list,
 * role.list and node.list as the person, the first and largest page of each, written by the
 * generated GrantPickersCodecV1. A picker's read the pipeline cannot make is reported to the
 * application's exception handler and carries neither result nor rejection, so the other pickers
 * still work. The controller holds no logic, so what the page is made of is named here.
 */
#[Internal]
final readonly class AccessGrantsRequest
{
    public function __construct(
        private ContributionProps $contributions,
        private PanelReads $reads,
        private GrantPickersCodecV1 $pickers,
        private Repository $config,
        private ExceptionHandler $exceptions,
    ) {}

    /**
     * The view of the page for the person: its sections point.
     *
     * @throws LogicException for a request the panel did not authenticate
     */
    public function view(Request $request): PanelView
    {
        return $this->contributions->view($request, AccessGrants::PAGE, [new RenderedPoint(AccessGrants::sections(), new AccessGrantsSectionsV1)]);
    }

    /**
     * The call of grant.list as the person who signed in, for the page after the id the address names.
     *
     * @throws LogicException for a request the panel did not authenticate
     */
    public function call(Request $request): QueryCall
    {
        return $this->reads->call($request, new ListGrants(ListCursor::of($request, GrantId::fromString(...))));
    }

    /**
     * The answer of the read as the page shows it.
     */
    public function answer(QueryResult $read): ReadAnswer
    {
        return $this->reads->answer($read, $this->reads->codec(AccessGrants::query()));
    }

    /**
     * The locales the form offers a grant: those the configured sites publish in, sorted by tag,
     * each once.
     *
     * @return list<Locale>
     */
    public function locales(): array
    {
        $locales = [];

        foreach (SitesConfig::read($this->config)->sites as $site) {
            foreach ($site->locales as $locale) {
                $locales[$locale->value] = $locale;
            }
        }

        ksort($locales, SORT_STRING);

        return array_values($locales);
    }

    /**
     * The pickers of the form as the optional prop PICKERS, read as the person only when the page
     * asks for it.
     *
     * @param  Closure(QueryCall): QueryResult  $run  QueryPipeline::run()
     *
     * @throws LogicException for a request the panel did not authenticate
     */
    public function pickers(Request $request, Closure $run): SharedProps
    {
        [$actors, $roles, $nodes] = AccessGrants::pickers();
        $calls = [
            $actors->name->value => $this->reads->call($request, new ListActors(limit: ListLimit::MAX)),
            $roles->name->value => $this->reads->call($request, new ListRoles(limit: ListLimit::MAX)),
            $nodes->name->value => $this->reads->call($request, new ListNodes(limit: ListLimit::MAX)),
        ];

        return new SharedProps([AccessGrants::PICKERS => new OptionalProp(fn (): array => InertiaProps::document($this->pickers->encode(new GrantPickers(
            actors: $this->read($calls, $actors, $run),
            nodes: $this->read($calls, $nodes, $run),
            roles: $this->read($calls, $roles, $run),
        ), ClassificationAccess::Public)))]);
    }

    /**
     * One picker's read: the query's result or rejection, or neither for a read the pipeline could
     * not make, which is reported.
     *
     * @param  array<string, QueryCall>  $calls
     * @param  Closure(QueryCall): QueryResult  $run
     */
    private function read(array $calls, CommandRef $query, Closure $run): PickerRead
    {
        $call = $calls[$query->name->value] ?? throw new LogicException(sprintf('The grants page has no call of %s.', $query->toString()));

        try {
            $answer = $this->reads->answer($run($call), $this->reads->codec($query));
        } catch (Throwable $failure) {
            $this->exceptions->report($failure);

            return new PickerRead(null, null);
        }

        return new PickerRead($answer->result, $answer->rejection);
    }
}
