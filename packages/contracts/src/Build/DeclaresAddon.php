<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Build;

use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Contracts\Addons\InvalidAddonManifest;
use Cbox\Cms\Contracts\Addons\ReservedAddonNamespace;
use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Implemented by an addon's service provider to declare its manifest (PRD 13.1, 13.2). The same
 * provider usually implements DeclaresScanRoots for the classes of the addon's hooks and
 * subscribers, under the manifest's package name.
 *
 * cms:build asks every registered provider that implements it, deferred providers included, and
 * compiles the manifests with the scan roots: it refuses a hook or a subscriber of the addon's
 * package that the manifest does not allow, two addons with one namespace, and an addon whose
 * core API version the kernel does not satisfy; it gives each hook of the addon a view of the plan
 * narrowed to the manifest's capabilities, and writes the schema contributions to schema.php. A
 * package without a manifest is not an addon: its hooks and subscribers are the application's or
 * a module's, and no manifest limits them.
 */
#[Experimental]
interface DeclaresAddon
{
    /**
     * @throws InvalidAddonManifest|ReservedAddonNamespace when the manifest cannot be built; cms:build reports it
     */
    public function addonManifest(): AddonManifest;
}
