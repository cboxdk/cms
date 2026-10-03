<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * An addon's panel bundle as cms:build read it from the bundle directory its PanelContributions
 * name (PRD 13.4): the manifest, or null when it could not be read, and what was wrong with the
 * files on disk, each described: a file missing or unreadable, a file whose SHA-384 is not the
 * manifest's, or a stylesheet with a rule outside the addon's cascade layer. The compiler reports
 * every one as registry_panel_bundle_invalid, with its own checks of the manifest.
 */
#[Experimental]
final readonly class AddonBundle
{
    /**
     * @param  list<string>  $problems
     */
    public function __construct(
        public ?BundleManifest $manifest,
        public array $problems = [],
    ) {}
}
