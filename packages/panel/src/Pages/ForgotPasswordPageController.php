<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Pages;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Identity\PasswordReset\Domain\Dto\ResetSettings;
use Cbox\Cms\Panel\Boundary\PanelPages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The page that asks for a password reset link (PRD 5.16). It holds no logic of its own.
 */
#[Internal]
final readonly class ForgotPasswordPageController
{
    public function __construct(
        private PanelPages $pages,
        private ResetSettings $settings,
    ) {}

    public function __invoke(Request $request): Response|JsonResponse
    {
        return $this->pages->forgotPassword($request, $this->settings);
    }
}
