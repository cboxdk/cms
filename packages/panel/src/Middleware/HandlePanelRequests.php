<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Middleware;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Panel\Domain\Dto\PanelBuild;
use Cbox\Cms\Panel\Views\PanelRootView;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Override;

/**
 * Inertia's middleware for the panel's pages: they render in the panel's root view, and their
 * asset version is the build's, so a browser that holds a page of an older build loads the new
 * one in full instead of mixing the two (Inertia answers 409 and the client reloads).
 */
#[Internal]
final class HandlePanelRequests extends Middleware
{
    /** @var string */
    #[Override]
    protected $rootView = PanelRootView::VIEW;

    public function __construct(private readonly PanelBuild $build) {}

    #[Override]
    public function version(Request $request): string
    {
        return $this->build->version;
    }
}
