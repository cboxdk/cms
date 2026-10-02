<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Pages;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Panel\Boundary\PanelPages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Any path below the panel that no other panel route has: the page Errors/NotFound, with 404. It
 * holds no logic of its own.
 */
#[Internal]
final readonly class NotFoundController
{
    public function __construct(private PanelPages $pages) {}

    public function __invoke(Request $request): Response|JsonResponse
    {
        return $this->pages->notFound($request);
    }
}
