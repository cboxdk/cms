<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Providers;

use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Contracts\Build\DeclaresAddon;
use Closure;
use Override;

/**
 * A provider that declares the manifest its closure builds, so a test can declare a manifest that
 * cannot be built.
 */
final readonly class AddonManifestProvider implements DeclaresAddon
{
    /**
     * @param  Closure(): AddonManifest  $manifest
     */
    public function __construct(private Closure $manifest) {}

    #[Override]
    public function addonManifest(): AddonManifest
    {
        return ($this->manifest)();
    }
}
