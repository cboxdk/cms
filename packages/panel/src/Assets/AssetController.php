<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Assets;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Panel\Boundary\PanelAssetResponse;
use Cbox\Cms\Panel\Domain\Dto\PanelBuild;
use Illuminate\Http\Response;

/**
 * A file of the panel's build, such as its script or stylesheet; PanelAssetResponse answers. It
 * holds no logic of its own.
 */
#[Internal]
final readonly class AssetController
{
    public function __construct(private PanelBuild $build) {}

    public function __invoke(string $path): Response
    {
        return PanelAssetResponse::of($this->build, $path);
    }
}
