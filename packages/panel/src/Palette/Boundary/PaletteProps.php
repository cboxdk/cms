<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Palette\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Core\Registry\Domain\Queries\ListActions;
use Cbox\Cms\Http\Inertia\Boundary\InertiaProps;
use Cbox\Cms\Panel\Boundary\Generated\PalettePropCodecV1;
use Cbox\Cms\Panel\Boundary\PanelReads;
use Cbox\Cms\Panel\Domain\Dto\PaletteProp;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Inertia\Inertia;
use LogicException;
use Throwable;

/**
 * The prop `palette` every page behind the login shares (GUARDRAILS 8, PRD 13.2, 13.4), from which
 * the command palette is built: the read of action.list as the person who signed in, through the
 * QueryPipeline from the session credential (PanelReads), with the result as the query's result
 * codec wrote it, the same document REST answers the person with at GET /v1/queries/action.list/v1,
 * or the problem details of a rejected read, written by the generated PalettePropCodecV1
 * (palette.v1.json). share() shares the prop as a closure Inertia resolves only when it renders a
 * page, so a logout or a command, which redirect, read nothing; the middleware SharePalette hands
 * the pipeline's run in as a closure, because Boundary does not use actions (GUARDRAILS 2.5).
 *
 * The palette never blanks the page: a read the pipeline cannot make, such as one over a registry
 * without action.list, is reported to the application's exception handler, as a contribution's
 * failed data query is, and the prop carries neither result nor rejection, which the palette shows
 * as its entries being unavailable, with what to do.
 */
#[Internal]
final readonly class PaletteProps
{
    /** The shared prop. */
    public const string PROP = 'palette';

    /** The query the prop is the read of, at its version. */
    public const string QUERY = 'action.list';

    public const int VERSION = 1;

    public function __construct(
        private PanelReads $reads,
        private PalettePropCodecV1 $codec,
        private ExceptionHandler $exceptions,
    ) {}

    /**
     * Shares the prop with the page the request renders, read when the page renders.
     *
     * @param  Closure(QueryCall): QueryResult  $run  QueryPipeline::run()
     */
    public function share(Request $request, Closure $run): void
    {
        Inertia::share(self::PROP, function () use ($request, $run): array {
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
        return InertiaProps::document($this->codec->encode(new PaletteProp(null, null), ClassificationAccess::Public));
    }

    /**
     * The call of action.list as the person who signed in.
     *
     * @throws LogicException for a request the panel did not authenticate
     */
    public function call(Request $request): QueryCall
    {
        return $this->reads->call($request, new ListActions);
    }

    /**
     * The prop as the page gets it: the read's result or its rejection.
     *
     * @return array<array-key, mixed>
     */
    public function prop(QueryResult $read): array
    {
        $answer = $this->reads->answer($read, $this->reads->codec(self::query()));

        return InertiaProps::document($this->codec->encode(new PaletteProp($answer->result, $answer->rejection), ClassificationAccess::Public));
    }

    public static function query(): CommandRef
    {
        return new CommandRef(new CommandName(self::QUERY), self::VERSION);
    }
}
