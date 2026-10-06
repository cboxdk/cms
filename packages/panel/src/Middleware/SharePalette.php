<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Middleware;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Panel\Palette\Boundary\PaletteProps;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shares the prop `palette` with every page behind the login (GUARDRAILS 8, PRD 13.4): the read of
 * action.list as the person who signed in, from which the command palette is built, so the palette
 * opens with Ctrl+K or Command+K from every page with the actions the person may run and the pages
 * they may open, as the server decided them. It runs after AuthenticatePanelSession put the session
 * credential on the request, and hands PaletteProps the pipeline's run, which reads only when a
 * page renders: a logout or a command, which redirect, read nothing. It holds no logic of its own.
 */
#[Internal]
final readonly class SharePalette
{
    public function __construct(
        private PaletteProps $props,
        private QueryPipeline $pipeline,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->props->share($request, fn (QueryCall $call): QueryResult => $this->pipeline->run($call));

        return $next($request);
    }
}
