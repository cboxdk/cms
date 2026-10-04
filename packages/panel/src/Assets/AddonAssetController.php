<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Assets;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Panel\Boundary\AddonAssetResponse;
use Cbox\Cms\Panel\Domain\Dto\ServedBundles;
use Illuminate\Http\Response;

/**
 * A file of an addon's panel bundle, by the bundle's hash; AddonAssetResponse answers. It holds
 * no logic of its own.
 */
#[Internal]
final readonly class AddonAssetController
{
    public function __construct(private ServedBundles $bundles) {}

    public function __invoke(string $addon, string $hash, string $path): Response
    {
        return AddonAssetResponse::of($this->bundles, $addon, $hash, $path);
    }
}
