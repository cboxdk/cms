<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Branding\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Panel\Branding\Domain\BrandImageType;
use Cbox\Cms\Panel\Branding\Domain\BrandRole;

/**
 * A file of the installation's brand, read once from the application (cbox-cms.panel.branding):
 * what it is for, its kind and its bytes. The panel serves it from its own origin under a name
 * with the first 16 hex digits of its SHA-256, so a changed file has a new address and the old one
 * can be cached for good.
 */
#[Internal]
final readonly class BrandFile
{
    /** The form of the name the panel serves a brand file under. */
    public const string NAME_PATTERN = '[a-z]+(?:-[a-z]+)?-[0-9a-f]{16}\.(?:svg|png)';

    public string $name;

    public function __construct(
        public BrandRole $role,
        public BrandImageType $type,
        public string $contents,
    ) {
        $this->name = sprintf('%s-%s.%s', $role->value, substr(hash('sha256', $contents), 0, 16), $type->value);
    }
}
