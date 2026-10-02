<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Pages;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Panel\Boundary\PanelPages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The page a password reset link opens (PRD 5.16), with the token of its address. It holds no logic
 * of its own.
 */
#[Internal]
final readonly class ResetPasswordPageController
{
    public function __construct(private PanelPages $pages) {}

    public function __invoke(Request $request, string $token): Response|JsonResponse
    {
        return $this->pages->resetPassword($request, $token);
    }
}
