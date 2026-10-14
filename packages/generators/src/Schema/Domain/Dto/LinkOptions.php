<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Fields\Link;
use Cbox\Cms\Contracts\Fields\LinkKind;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ColumnShape;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\PhpType;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\TypeScriptType;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValidationRule;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValueShape;
use Cbox\Cms\Generators\Descriptor\Domain\ValidationRuleName;
use Cbox\Cms\Generators\Schema\Domain\FieldOptions;
use Cbox\Cms\Generators\Schema\Domain\FieldTypes\LinkFieldType;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;
use Override;

/**
 * A `link` field (B2-T76): one target of the kinds the field allows, every kind when the file
 * leaves `kinds` out, in the order of LinkKind. The value is the contracts' Link, stored as a jsonb
 * object of its kind and its target.
 */
#[Internal]
final readonly class LinkOptions implements FieldOptions
{
    /** The kinds a link allows when its file leaves `kinds` out: every kind, as the schema's default. */
    public const array DEFAULT_KINDS = ['entry', 'node', 'url'];

    /** @var non-empty-list<LinkKind> in the order of LinkKind */
    public array $kinds;

    /**
     * @param  list<LinkKind>  $kinds
     */
    public function __construct(array $kinds)
    {
        $ordered = array_values(array_filter(LinkKind::cases(), static fn (LinkKind $kind): bool => in_array($kind, $kinds, true)));
        $this->kinds = $ordered === [] ? LinkKind::cases() : $ordered;
    }

    #[Override]
    public function typeName(): string
    {
        return LinkFieldType::NAME;
    }

    #[Override]
    public function problems(SourceLocation $field): array
    {
        return [];
    }

    #[Override]
    public function nestedFields(): array
    {
        return [];
    }

    /**
     * A jsonb object whose kind is one the field allows; ELSE holds for null, as for a group.
     */
    #[Override]
    public function describeColumn(string $column): ColumnShape
    {
        return new ColumnShape('jsonb', [sprintf(
            "CASE WHEN jsonb_typeof(%s) = 'object' THEN coalesce((%s ->> 'kind') IN (%s), false) ELSE %s IS NULL END",
            $column,
            $column,
            implode(', ', array_map(static fn (LinkKind $kind): string => "'".$kind->value."'", $this->kinds)),
            $column,
        )]);
    }

    /**
     * The contracts' Link; in TypeScript the runtime's Link. The rules are `link`, then `kinds`
     * with the kinds the field allows.
     */
    #[Override]
    public function describeValue(array $fields): ValueShape
    {
        return new ValueShape(
            new PhpType('\\'.Link::class, '\\'.Link::class),
            new TypeScriptType('Link'),
            [
                new ValidationRule(ValidationRuleName::Link),
                new ValidationRule(ValidationRuleName::Kinds, $this->kindNames()),
            ],
        );
    }

    /**
     * @return list<string>
     */
    public function kindNames(): array
    {
        return array_map(static fn (LinkKind $kind): string => $kind->value, $this->kinds);
    }
}
