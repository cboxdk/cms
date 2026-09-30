<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\FieldTypes;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\FieldTypes\FieldTypeContribution;
use Cbox\Cms\Generators\Schema\Domain\FieldTypeContributor;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Override;

/**
 * The field types of one addon in the FieldTypeRegistry (PRD 13.1, 13.3): the contributions its
 * Cbox\Cms\Contracts\FieldTypes\FieldTypeContributor returns, in the addon's namespace, so the
 * registry holds each name to that namespace as it holds every contributor's.
 */
#[Internal]
final readonly class AddonFieldTypes implements FieldTypeContributor
{
    /** @var list<ContributionFieldType> */
    private array $types;

    /**
     * @param  list<FieldTypeContribution>  $contributions
     */
    public function __construct(private Owner $owner, array $contributions)
    {
        $this->types = array_map(static fn (FieldTypeContribution $contribution): ContributionFieldType => new ContributionFieldType($contribution), $contributions);
    }

    #[Override]
    public function owner(): Owner
    {
        return $this->owner;
    }

    #[Override]
    public function fieldTypes(): array
    {
        return $this->types;
    }
}
