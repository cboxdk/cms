<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Http\Inertia\Boundary\InertiaOutcome;
use Cbox\Cms\Panel\Domain\Dto\PanelBuild;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Override;

/**
 * Inertia's middleware for the panel's pages: they render in the panel's root view, and their
 * asset version is the build's, so a browser that holds a page of an older build loads the new
 * one in full instead of mixing the two (Inertia answers 409 and the client reloads). Every page
 * gets Inertia's `errors` prop and flash data from Laravel's session, and the prop `problem`, the
 * problem details of a command the Inertia profile rejected, which InertiaOutcome flashed for the
 * page the redirect renders, null on every other page. It sits in Boundary because the props it
 * shares are untyped values of Laravel's session.
 */
#[Internal]
final class HandlePanelRequests extends Middleware
{
    /** The panel's root view, resources/views/app.blade.php below the module. */
    public const string ROOT_VIEW = 'cms-panel::app';

    /** @var string */
    #[Override]
    protected $rootView = self::ROOT_VIEW;

    public function __construct(private readonly PanelBuild $build) {}

    #[Override]
    public function version(Request $request): string
    {
        return $this->build->version;
    }

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
