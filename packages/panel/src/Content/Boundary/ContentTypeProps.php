<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Content\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Core\Schema\Domain\Queries\ListTypes;
use Cbox\Cms\Http\Inertia\Boundary\InertiaProps;
use Cbox\Cms\Panel\Boundary\Generated\TypesPropCodecV1;
use Cbox\Cms\Panel\Boundary\PanelReads;
use Cbox\Cms\Panel\Content\Domain\ContentTypes;
use Cbox\Cms\Panel\Domain\Dto\TypesProp;
use Cbox\Cms\Panel\Domain\PanelRoute;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use Inertia\Inertia;
use LogicException;
use Throwable;

/**
 * The prop `types` every page behind the login shares (PRD 11.12, 13.4), from which the shell
 * builds the Content section of the navigation and the content list shows each entry's type and
 * title field: the address of the content list, and the read of type.list as the person who signed in, through the QueryPipeline from
 * the session credential (PanelReads), with the result as the query's result codec wrote it, the
 * same document REST answers the person with at GET /v1/queries/type.list/v1, or the problem
 * details of a rejected read, written by the generated TypesPropCodecV1 (types.v1.json). share()
 * shares the prop as a closure Inertia resolves only when it renders a page, so a logout or a
 * command, which redirect, read nothing; the middleware ShareContentTypes hands the pipeline's run
 * in as a closure, because Boundary does not use actions (GUARDRAILS 2.5).
 *
 * The prop never blanks the page: a read the pipeline cannot make is reported to the
 * application's exception handler, and the prop carries neither result nor rejection, which the
 * navigation shows as the types being unavailable.
 */
#[Internal]
final readonly class ContentTypeProps
{
    public function __construct(
        private PanelReads $reads,
        private TypesPropCodecV1 $codec,
        private ExceptionHandler $exceptions,
        private UrlGenerator $urls,
    ) {}

    /**
     * Shares the prop with the page the request renders, read when the page renders.
     *
     * @param  Closure(QueryCall): QueryResult  $run  QueryPipeline::run()
     */
    public function share(Request $request, Closure $run): void
    {
        Inertia::share(ContentTypes::PROP, function () use ($request, $run): array {
            $call = $this->call($request);

            try {
                return $this->prop($run($call));
            } catch (Throwable $failure) {
                $this->exceptions->report($failure);

                return $this->unavailable();
            }
        });
    }

    /**
     * The prop of a read the pipeline could not make: neither result nor rejection.
     *
     * @return array<array-key, mixed>
     */
    public function unavailable(): array
    {
        return InertiaProps::document($this->codec->encode(new TypesProp($this->content(), null, null), ClassificationAccess::Public));
    }

    /**
     * The address of the content list, relative to the host.
     */
    public function content(): string
    {
        return $this->urls->route(PanelRoute::ContentList->value, [], false);
    }

    /**
     * The call of type.list as the person who signed in.
     *
     * @throws LogicException for a request the panel did not authenticate
     */
    public function call(Request $request): QueryCall
    {
        return $this->reads->call($request, new ListTypes);
    }

    /**
     * The prop as the page gets it: the read's result or its rejection.
     *
     * @return array<array-key, mixed>
     */
    public function prop(QueryResult $read): array
    {
        $answer = $this->reads->answer($read, $this->reads->codec(ContentTypes::query()));

        return InertiaProps::document($this->codec->encode(new TypesProp($this->content(), $answer->result, $answer->rejection), ClassificationAccess::Public));
    }
}
