<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The directory of each installed addon's panel bundle, by namespace, as the addons' manifests
 * name them in this process (PRD 13.4). The compiled registry holds the bundles' files and hashes
 * and no path on disk; the panel reads the files from these directories.
 */
#[Internal]
final readonly class BundleDirectories
{
    /**
     * @param  array<string, string>  $directories  the absolute directory by namespace
     */
    public function __construct(public array $directories = []) {}

    public static function none(): self
    {
        return new self;
    }

    public function of(AddonNamespace $addon): ?string
    {
        return $this->directories[$addon->value] ?? null;
    }
}
