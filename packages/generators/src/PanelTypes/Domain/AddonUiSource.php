<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\PanelTypes\Domain;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\PanelTypes\Domain\Dto\AddonUi;

/**
 * Where cms:panel:types reads an installed addon's UI: the contributions cms:build compiled from its
 * manifest, the points' props, and the JSON Schemas of its data queries and of the commands it
 * reads and issues. The port of WritePanelTypes; the generators bind it to
 * Adapter\RegistryAddonUiSource.
 */
#[Internal]
interface AddonUiSource
{
    /**
     * @throws GenerationFailed with generate_panel_addon_unknown, generate_registry_unreadable, generate_schema_missing or generate_schema_invalid
     */
    public function addon(AddonNamespace $namespace): AddonUi;
}
