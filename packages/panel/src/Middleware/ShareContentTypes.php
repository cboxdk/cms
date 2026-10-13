<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Middleware;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Panel\Content\Boundary\ContentTypeProps;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shares the prop `types` with every page behind the login (PRD 11.12, 13.4): the read of
 * type.list as the person who signed in, from which the shell builds the Content section of the
 * navigation, an entry to the content list of each type. It runs after AuthenticatePanelSession
 * put the session credential on the request, and hands ContentTypeProps the pipeline's run, which
 * reads only when a page renders. It holds no logic of its own.
 */
#[Internal]
final readonly class ShareContentTypes
{
    public function __construct(
        private ContentTypeProps $props,
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
