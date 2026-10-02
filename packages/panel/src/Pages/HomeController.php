<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Pages;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Panel\Boundary\PanelPages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The panel's start page for a person who logged in (PRD 13.4). It holds no logic of its own.
 */
#[Internal]
final readonly class HomeController
{
    public function __construct(private PanelPages $pages) {}

    public function __invoke(Request $request): Response|JsonResponse
    {
        return $this->pages->home($request);
    }
}
