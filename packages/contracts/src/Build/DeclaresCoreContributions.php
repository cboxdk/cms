<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Build;

use Cbox\Cms\Contracts\Addons\InvalidAddonManifest;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\InvalidPanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PanelContribution;

/**
 * Implemented by the service provider of a module of cboxdk/cms to declare the core's own
 * contributions to the panel's points (PRD 13.4), in the namespace cms: the contributions the
 * panel's pages give their own points, such as a profile section of the who-am-I page, so a page
 * renders the core's items through the same host, order and overrides as an addon's. An addon
 * declares its contributions in its manifest (DeclaresAddon), never here.
 *
 * cms:build asks every registered provider that implements it, deferred providers included, and
 * compiles the contributions with the addons' onto panel.php: each id is `cms.<local>`, once in
 * the installation, at a declared point of its kind, which may be #[Internal], the core's own
 * wiring. A contribution that runs code is registered under its id by the panel's own JavaScript
 * in the namespace cms, never by a bundle. A provider of a package other than cboxdk/cms that
 * implements it is a problem of the build.
 */
#[Internal]
interface DeclaresCoreContributions
{
    /**
     * @return list<PanelContribution>
     *
     * @throws InvalidAddonManifest|InvalidPanelPoint when a contribution cannot be built; cms:build reports it
     */
    public function coreContributions(): array;
}
