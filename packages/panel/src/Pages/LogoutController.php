<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Pages;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Identity\Sessions\Actions\EndSessions;
use Cbox\Cms\Panel\Boundary\PanelSessions;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A logout (PRD 5.16): EndSessions ends the CMS session the request was authenticated with, and
 * PanelSessions ends Laravel's, clears both cookies and sends the browser to the login page. It
 * holds no logic of its own.
 */
#[Internal]
final readonly class LogoutController
{
    public function __construct(
        private PanelSessions $sessions,
        private EndSessions $ends,
    ) {}

    public function __invoke(Request $request): Response
    {
        $this->ends->logout($this->sessions->token($request));

        return $this->sessions->ended($request);
    }
}
