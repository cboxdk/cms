<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Inertia\Adapter;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Http\Inertia\Boundary\InertiaOutcome;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Override;

/**
 * The Inertia middleware of the pages that post to the Inertia profile (GUARDRAILS 2.1): Inertia's
 * own, with its root view `app`, its `errors` prop and its flash data, and the problem details of a
 * rejected call as the page prop `problem`, from the session, where InertiaOutcome flashed it for
 * the page the redirect renders; null on every other page.
 */
#[Experimental]
final class InertiaMiddleware extends Middleware
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            InertiaOutcome::PROBLEM_PROP => $request->hasSession() ? $request->session()->get(InertiaOutcome::PROBLEM) : null,
        ];
    }
}
