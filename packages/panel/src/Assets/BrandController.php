<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Assets;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Panel\Boundary\BrandFileResponse;
use Cbox\Cms\Panel\Branding\Domain\Dto\Branding;
use Illuminate\Http\Response;

/**
 * A file of the installation's brand; BrandFileResponse answers. It holds no logic of its own.
 */
#[Internal]
final readonly class BrandController
{
    public function __construct(private Branding $branding) {}

    public function __invoke(string $name): Response
    {
        return BrandFileResponse::of($this->branding, $name);
    }
}
