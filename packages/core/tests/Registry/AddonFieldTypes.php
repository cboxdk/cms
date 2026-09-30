<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Contracts\FieldTypes\FieldTypeContributor;
use Override;

/**
 * The field type contributor the registry fixtures' addon manifests name. cms:build only checks
 * that the class implements FieldTypeContributor; cms:generate makes it and reads its field
 * types, which the registry tests never do.
 */
final readonly class AddonFieldTypes implements FieldTypeContributor
{
    #[Override]
    public function fieldTypes(): array
    {
        return [];
    }
}
