<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\TypeTables;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Ids\EntryId;

/**
 * A filter of a type table query on a filterable relation (PRD 5.11, 8.8): the rows whose released
 * relation in the column's field points to the target, through the kernel's relations table, and,
 * for a relation whose targets carry attributes, an edge to the target whose attributes equal the
 * attribute filters', each on a filterable attribute.
 *
 * An order by the relation's sort_by field alone, after such a filter, is the reverse listing of
 * the target: the newest sources of one target are read in the order of the sort key the kernel
 * wrote onto the edges at release, which is the sort_by field's released value.
 */
#[Experimental]
final readonly class RelationFilter
{
    /** @var list<AttributeFilter> each attribute once */
    public array $attributes;

    /**
     * @throws InvalidTypeTableQuery
     */
    public function __construct(
        public string $column,
        public EntryId $target,
        AttributeFilter ...$attributes,
    ) {
        TypeTableQuery::assertColumn($column);

        $byHandle = [];

        foreach ($attributes as $attribute) {
            if (isset($byHandle[$attribute->attribute->value])) {
                throw InvalidTypeTableQuery::repeatedAttribute($column, $attribute->attribute->value);
            }

            $byHandle[$attribute->attribute->value] = $attribute;
        }

        $this->attributes = array_values($attributes);
    }

    /**
     * The attribute filter on the attribute, or null.
     */
    public function attribute(FieldHandle $handle): ?AttributeFilter
    {
        return array_find($this->attributes, static fn (AttributeFilter $filter): bool => $filter->attribute->equals($handle));
    }
}
