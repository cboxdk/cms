<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\FieldTypes\Fixtures;

use Cbox\Cms\Contracts\FieldTypes\FieldTypeContributor;
use Override;

/**
 * The field type contributor of the fixture addon acme/cms-ratings, namespace `acme`: the field
 * types acme:grade and acme:stars.
 */
final readonly class AcmeFieldTypes implements FieldTypeContributor
{
    public const string PACKAGE = 'acme/cms-ratings';

    #[Override]
    public function fieldTypes(): array
    {
        return [new StarsFieldType, new GradeFieldType];
    }
}
