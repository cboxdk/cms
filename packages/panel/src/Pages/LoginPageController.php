<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Pages;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Panel\Boundary\PanelPages;
use Cbox\Cms\Panel\Boundary\PanelSessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The login page (PRD 5.16), or the panel's start for a browser whose session verifies. It holds no
 * logic of its own.
 */
#[Internal]
final readonly class LoginPageController
{
    public function __construct(
        private PanelSessions $sessions,
        private PanelPages $pages,
    ) {}

    public function __invoke(Request $request): Response|JsonResponse
    {
        return $this->sessions->signedIn($request) ?? $this->pages->login($request);
    }
}
