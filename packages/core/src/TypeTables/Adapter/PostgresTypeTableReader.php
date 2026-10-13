<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\TypeTables\Adapter;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NullValue;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Schema\ColumnDefinition;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\TypeTables\ColumnFilter;
use Cbox\Cms\Contracts\TypeTables\ColumnOrder;
use Cbox\Cms\Contracts\TypeTables\CursorKey;
use Cbox\Cms\Contracts\TypeTables\FilterOperator;
use Cbox\Cms\Contracts\TypeTables\InvalidTypeTableQuery;
use Cbox\Cms\Contracts\TypeTables\RelationFilter;
use Cbox\Cms\Contracts\TypeTables\SortDirection;
use Cbox\Cms\Contracts\TypeTables\TypeTableCursor;
use Cbox\Cms\Contracts\TypeTables\TypeTablePage;
use Cbox\Cms\Contracts\TypeTables\TypeTableQuery;
use Cbox\Cms\Contracts\TypeTables\TypeTableReader;
use Cbox\Cms\Contracts\TypeTables\TypeTableRow;
use Cbox\Cms\Core\Entries\Domain\RelationSortKey;
use Cbox\Cms\Core\Reads\Adapter\ConnectionQueryTransaction;
use Cbox\Cms\Core\Reads\Domain\Dto\AuditedRead;
use Cbox\Cms\Core\Reads\Domain\Dto\ReadAuditRecord;
use Cbox\Cms\Core\Reads\Domain\ReadableFields;
use Cbox\Cms\Core\Reads\Domain\ReadAudit;
use Cbox\Cms\Core\TypeTables\Boundary\TypeTableColumns;
use Cbox\Cms\Core\TypeTables\Domain\Dto\SortKey;
use Cbox\Cms\Core\TypeTables\Domain\UnreadableTypeTable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Override;

/**
 * The TypeTableReader on Postgres (PRD 5.10, 8.8, 11.6), bound to the contract in
 * `cbox-cms.contracts`. It runs on the default connection, or the one named, inside the caller's
 * read transaction, whose actor context row level security reads, and uses the query builder only,
 * every value a bound parameter (GUARDRAILS 6).
 *
 * A page is one query of the type table, `<owner>__<handle>`, for the released rows of the
 * variant: the filters, the explicit access predicate, the order by the order's columns and then
 * cms_entry_id, the keyset condition after the cursor, and one row more than the limit, so the page
 * knows whether a next one follows. The access predicate (PRD 5.10) is `cms_owner_actor` equal to
 * the context's actor, or the home node reached by one of the context's regions: when the regions
 * reach at most `regionNodeLimit` nodes, a first query of `nodes` lists them and the predicate is
 * `cms_home_node` in that list; otherwise the page left joins `nodes` and tests the node's path
 * with `<@` against each region's path and exceptions, so a row the actor owns outside its regions
 * stays. So a page costs two queries, whatever the number of matching rows (GUARDRAILS 4.1), and a
 * context without an actor and without regions reads no row and runs none.
 *
 * Each row's fields are those the context may read, TypeDefinition::readable() of the decoded
 * row (PRD 6.2, 12.2), the rule the query pipeline strips by too; a filter or order on a field the
 * context may not read is refused before the page is read (TypeTableQuery::assertReadableBy()).
 * When the rows give an actor a field that requires the read audit, a sensitive one (PRD 12.12),
 * the page writes it through the ReadAudit in the caller's read transaction, under the query name
 * `type_tables.page` version 1 and at the read's position, the xmin of its snapshot; the ReadAudit
 * writes on its own connection, the default one in the container, so a reader on a named
 * connection is given a ReadAudit on the same one.
 *
 * Null sorts as the greatest value, as Postgres sorts it by default, so the type table's indexes
 * on (cms_stage, cms_locale, column, cms_entry_id) serve both directions. The entry id follows the
 * direction of the last key.
 *
 * A relation filter (PRD 5.11) keeps the rows with a released edge in `relations` from the row's
 * entry, in the variant that holds the relation, to the target, with the attribute filter's value
 * as the edge's filter key. When the query is a target's reverse listing (TypeTableQuery::sortedRelation()),
 * the page joins that relation's edges and orders and pages by their sort key and the source entry
 * instead of the sort_by column, so it reads `relations_sorted`, or the partial index of an
 * attribute it filters on, backwards from the cursor, which the cursor's sort key, encoded as the
 * kernel writes it (RelationSortKey), bounds: an index scan of the page's rows, whatever
 * the number of sources. The sort key is the sort_by field's released value, so the order and the
 * cursor are the field's. An edge to the target that repeats one before it in the field, which a
 * relation whose targets carry attributes allows, is left out, so each row comes once.
 */
#[Experimental]
final readonly class PostgresTypeTableReader implements TypeTableReader
{
    /** The most nodes the regions may reach for the predicate to list them instead of joining nodes. */
    public const int REGION_NODE_LIMIT = 100;

    /** The name the read audit records a page of a type table under; no query action has it. */
    public const string AUDIT_QUERY = 'type_tables.page';

    public const int AUDIT_QUERY_VERSION = 1;

    private const string TABLE = 't';

    private const string NODES = 'n';

    /** The alias of the edges of a reverse listing. */
    private const string EDGES = 'r';

    private const string RELATIONS = 'relations';

    /**
     * @param  string|null  $connection  the connection name; null for the default connection
     * @param  int  $regionNodeLimit  the most nodes the regions may reach for the predicate to list them
     */
    public function __construct(
        private ConnectionResolverInterface $connections,
        private TypeCatalog $catalog,
        private ReadAudit $audit,
        private ?string $connection = null,
        private int $regionNodeLimit = self::REGION_NODE_LIMIT,
    ) {}

    #[Override]
    public function page(TypeTableQuery $query, AccessContext $access): TypeTablePage
    {
        $type = $this->catalog->named($query->type);

        if (! $type instanceof TypeDefinition) {
            throw InvalidTypeTableQuery::unknownType($query->type);
        }

        $query->assertAllowedBy($type);
        $query->assertReadableBy($type, $access);

        $actor = $access->principal instanceof ActorPrincipal ? $access->principal->actor : null;

        if (! $actor instanceof ActorId && $access->regions === []) {
            return new TypeTablePage([], null);
        }

        $db = $this->connections->connection($this->connection);
        $builder = $db->table($query->type->table().' as '.self::TABLE)
            ->select(self::TABLE.'.*')
            ->where(self::column('cms_stage'), '=', 'released')
            ->where(self::column('cms_locale'), '=', $query->variant->value);

        foreach ($query->filters as $filter) {
            $this->filter($builder, $filter);
        }

        $sorted = $query->sortedRelation($type);

        foreach ($query->relations as $relation) {
            if ($relation === $sorted) {
                $builder->join(self::RELATIONS.' as '.self::EDGES, fn (JoinClause $edges): JoinClause => $this->edges($edges, $type, $query->variant, $relation, self::EDGES));
                $this->firstEdge($builder, $type, $relation);
            } else {
                $builder->whereExists(fn (Builder $exists): Builder => $this->edges($exists->from(self::RELATIONS.' as e')->select('e.ordinal'), $type, $query->variant, $relation, 'e'));
            }
        }

        $this->access($db, $builder, $actor, $access->regions);
        $keys = $this->keys($type, $query->order, $sorted instanceof RelationFilter);
        $direction = $this->order($builder, $keys);

        if ($query->after instanceof TypeTableCursor) {
            $this->after($builder, $keys, $query->after, $direction, $sorted instanceof RelationFilter);
        }

        $rows = $builder->limit($query->limit + 1)->get()->all();
        $more = count($rows) > $query->limit;
        $read = [];

        foreach (array_slice($rows, 0, $query->limit) as $row) {
            $values = (array) $row;
            $entry = $values['cms_entry_id'] ?? null;

            if (! is_string($entry)) {
                throw UnreadableTypeTable::missingColumn('cms_entry_id');
            }

            $read[] = new TypeTableRow(EntryId::fromString($entry), $type->readable(TypeTableColumns::decode($type, $values), $access->classificationAccess, $access->readsAsAgent()));
        }

        $this->audit($db, $type, $access, $read);
        $last = $read === [] ? null : $read[count($read) - 1];

        return new TypeTablePage($read, $more && $last instanceof TypeTableRow ? $this->cursor($type, $query->order, $last) : null);
    }

    /**
     * The read audit of the rows' fields that require it (PRD 12.12), in the caller's read
     * transaction with the read's position, as the query pipeline writes it. Only an actor reads a
     * field that requires it.
     *
     * @param  list<TypeTableRow>  $rows
     */
    private function audit(ConnectionInterface $db, TypeDefinition $type, AccessContext $access, array $rows): void
    {
        if (! $access->principal instanceof ActorPrincipal) {
            return;
        }

        $reads = array_values(array_filter(array_map(
            static fn (TypeTableRow $row): ?AuditedRead => ReadableFields::auditedOf($type, $row->entry, $row->fields),
            $rows,
        )));

        if ($reads === []) {
            return;
        }

        $position = $db->scalar(ConnectionQueryTransaction::POSITION);

        if (! is_string($position)) {
            throw UnreadableTypeTable::position();
        }

        $this->audit->record(new ReadAuditRecord($access->principal->actor, new CommandName(self::AUDIT_QUERY), self::AUDIT_QUERY_VERSION, new CommitPosition($position), $reads));
    }

    private function filter(Builder $builder, ColumnFilter $filter): void
    {
        $column = self::column($filter->column);
        $values = array_map(static fn (FieldValue $value): bool|int|string => TypeTableColumns::binding($filter->column, $value), $filter->values);

        match ($filter->operator) {
            FilterOperator::Eq => $builder->where($column, '=', $values[0]),
            FilterOperator::Neq => $builder->where($column, '<>', $values[0]),
            FilterOperator::Lt => $builder->where($column, '<', $values[0]),
            FilterOperator::Lte => $builder->where($column, '<=', $values[0]),
            FilterOperator::Gt => $builder->where($column, '>', $values[0]),
            FilterOperator::Gte => $builder->where($column, '>=', $values[0]),
            FilterOperator::In => $builder->whereIn($column, $values),
            FilterOperator::NotIn => $builder->whereNotIn($column, $values),
            FilterOperator::IsNull => $builder->whereNull($column),
            FilterOperator::IsNotNull => $builder->whereNotNull($column),
        };
    }

    /**
     * The explicit access predicate: the actor's own rows, or the rows whose home node a region
     * reaches, listed when the regions reach few nodes and joined otherwise.
     *
     * @param  list<AccessRegion>  $regions
     */
    private function access(ConnectionInterface $db, Builder $builder, ?ActorId $actor, array $regions): void
    {
        $nodes = null;

        if ($regions !== []) {
            $reached = $db->table('nodes as '.self::NODES)
                ->where(fn (Builder $reach): Builder => self::reach($reach, $regions))
                ->limit($this->regionNodeLimit + 1)
                ->pluck(self::NODES.'.id')
                ->all();

            if (count($reached) <= $this->regionNodeLimit) {
                $nodes = array_values(array_filter($reached, is_string(...)));
            } else {
                $builder->leftJoin('nodes as '.self::NODES, self::NODES.'.id', '=', self::column('cms_home_node'));
            }
        }

        $builder->where(static function (Builder $predicate) use ($actor, $regions, $nodes): void {
            if ($actor instanceof ActorId) {
                $predicate->orWhere(self::column('cms_owner_actor'), '=', $actor->toString());
            }

            if ($nodes !== null && $nodes !== []) {
                $predicate->orWhereIn(self::column('cms_home_node'), $nodes);
            } elseif ($nodes === null && $regions !== []) {
                $predicate->orWhere(static fn (Builder $reach): Builder => self::reach($reach, $regions));
            }
        });
    }

    /**
     * The nodes a region reaches: at or below its path and in none of its exceptions. The regions
     * are disjoint, so a node reached by one is in no other's exceptions (AccessContext).
     *
     * @param  list<AccessRegion>  $regions
     */
    private static function reach(Builder $builder, array $regions): Builder
    {
        foreach ($regions as $region) {
            $builder->orWhere(static function (Builder $inside) use ($region): void {
                $inside->where(self::NODES.'.path', '<@', $region->path->value);

                foreach ($region->exceptions as $exception) {
                    $inside->whereNot(self::NODES.'.path', '<@', $exception->value);
                }
            });
        }

        return $builder;
    }

    /**
     * The keys of the order, each as the expression it sorts by and how a value of it is bound: the
     * row's column, or for a reverse listing the sort key on the joined edges, which holds the
     * column's released value as JSON.
     *
     * @param  list<ColumnOrder>  $order
     * @return list<SortKey>
     */
    private function keys(TypeDefinition $type, array $order, bool $sorted): array
    {
        return array_map(fn (ColumnOrder $key): SortKey => new SortKey(
            $sorted ? self::EDGES.'.sort_key' : self::column($key->column),
            $key->direction,
            $sorted ? static fn (FieldValue $value): string => RelationSortKey::of($value) ?? '' : static fn (FieldValue $value): bool|int|string => TypeTableColumns::binding($key->column, $value),
            ! $sorted && $this->notNull($type, $key->column),
        ), $order);
    }

    /**
     * The entry id's expression: the row's, or the source's on the joined edges of a reverse
     * listing, which the edges' index ends with.
     */
    private function entryColumn(bool $sorted): string
    {
        return $sorted ? self::EDGES.'.source_entry_id' : self::column('cms_entry_id');
    }

    /**
     * Orders by the keys and then the entry id, and gives the entry id's direction.
     *
     * @param  list<SortKey>  $keys
     */
    private function order(Builder $builder, array $keys): SortDirection
    {
        $direction = SortDirection::Ascending;

        foreach ($keys as $key) {
            $builder->orderBy($key->expression, $key->direction->value);
            $direction = $key->direction;
        }

        $builder->orderBy($this->entryColumn($keys !== [] && $keys[0]->expression === self::EDGES.'.sort_key'), $direction->value);

        return $direction;
    }

    /**
     * The released edges of the relation from the row's entry to the filter's target, in the
     * variant that holds the relation (PRD 10.1), with the attribute filters' values: as the join
     * of a reverse listing, or as the condition of an EXISTS.
     *
     * @template T of Builder
     *
     * @param  T  $edges
     * @return T
     */
    private function edges(Builder $edges, TypeDefinition $type, VariantKey $variant, RelationFilter $relation, string $alias): Builder
    {
        $field = TypeTableQuery::fieldOf($type, $relation->column);
        $held = $field->heldBy($variant, $type->capabilities->localization) ? $variant : VariantKey::shared();
        if ($edges instanceof JoinClause) {
            $edges->on($alias.'.source_entry_id', '=', self::column('cms_entry_id'));
        } else {
            $edges->whereColumn($alias.'.source_entry_id', '=', self::column('cms_entry_id'));
        }

        $edges->where($alias.'.variant', '=', $held->value)
            ->where($alias.'.stage', '=', 'released')
            ->where($alias.'.field', '=', $field->address())
            ->where($alias.'.target_entry_id', '=', $relation->target->toString());

        foreach ($relation->attributes as $attribute) {
            $edges->where($alias.'.filter_key', '=', $attribute->text());
        }

        return $edges;
    }

    /**
     * Leaves out the joined edge of a reverse listing when an edge before it in the field points
     * to the same target with the same attributes, so each row comes once.
     */
    private function firstEdge(Builder $builder, TypeDefinition $type, RelationFilter $relation): void
    {
        if (TypeTableQuery::fieldOf($type, $relation->column)->relation?->meta !== true) {
            return;
        }

        $builder->whereNotExists(function (Builder $earlier) use ($relation): void {
            $earlier->from(self::RELATIONS.' as f')
                ->select('f.ordinal')
                ->whereColumn('f.source_entry_id', '=', self::EDGES.'.source_entry_id')
                ->whereColumn('f.variant', '=', self::EDGES.'.variant')
                ->whereColumn('f.stage', '=', self::EDGES.'.stage')
                ->whereColumn('f.field', '=', self::EDGES.'.field')
                ->whereColumn('f.target_entry_id', '=', self::EDGES.'.target_entry_id')
                ->whereColumn('f.ordinal', '<', self::EDGES.'.ordinal');

            foreach ($relation->attributes as $attribute) {
                $earlier->where('f.filter_key', '=', $attribute->text());
            }
        });
    }

    /**
     * The keyset condition: the rows after the cursor's row in the order. For the keys k1 to kn and
     * the entry id, a row is after when, for some i, it equals the cursor on k1 to k(i-1) and is
     * after it on ki, with null as the greatest value. When the first key's value bounds the rest,
     * the condition starts with that bound, so the index scan starts at the cursor.
     *
     * @param  list<SortKey>  $keys
     */
    private function after(Builder $builder, array $keys, TypeTableCursor $cursor, SortDirection $entryDirection, bool $sorted): void
    {
        /** @var list<array{SortKey, FieldValue}> $at */
        $at = [];

        foreach ($keys as $index => $key) {
            $at[] = [$key, $cursor->keys[$index]->value];
        }

        if ($at !== []) {
            $this->bound($builder, ...$at[0]);
        }

        $entry = $cursor->entry->toString();
        $entryColumn = $this->entryColumn($sorted);

        $builder->where(static function (Builder $after) use ($at, $entry, $entryColumn, $entryDirection): void {
            foreach ($at as $position => [$key, $value]) {
                if ($key->direction === SortDirection::Ascending && $value instanceof NullValue) {
                    continue;
                }

                $after->orWhere(static function (Builder $branch) use ($at, $position): void {
                    self::equalBefore($branch, $at, $position);
                    self::beyond($branch, ...$at[$position]);
                });
            }

            $after->orWhere(static function (Builder $branch) use ($at, $entry, $entryColumn, $entryDirection): void {
                self::equalBefore($branch, $at, count($at));
                $branch->where($entryColumn, $entryDirection === SortDirection::Descending ? '<' : '>', $entry);
            });
        });
    }

    /**
     * The bound the first key's value sets on the rows after the cursor, where one holds without
     * an OR: at or below it descending, where nulls came first; at or above it ascending on a
     * column that holds no null; and null ascending after a null.
     */
    private function bound(Builder $builder, SortKey $key, FieldValue $value): void
    {
        if ($value instanceof NullValue) {
            if ($key->direction === SortDirection::Ascending) {
                $builder->whereNull($key->expression);
            }

            return;
        }

        if ($key->direction === SortDirection::Descending) {
            $builder->where($key->expression, '<=', ($key->bind)($value));
        } elseif ($key->notNull) {
            $builder->where($key->expression, '>=', ($key->bind)($value));
        }
    }

    /**
     * The rows equal to the cursor on the keys before the position.
     *
     * @param  list<array{SortKey, FieldValue}>  $at
     */
    private static function equalBefore(Builder $builder, array $at, int $position): void
    {
        foreach (array_slice($at, 0, $position) as [$key, $value]) {
            if ($value instanceof NullValue) {
                $builder->whereNull($key->expression);
            } else {
                $builder->where($key->expression, '=', ($key->bind)($value));
            }
        }
    }

    /**
     * The rows after the value on one key, null being the greatest value.
     */
    private static function beyond(Builder $builder, SortKey $key, FieldValue $value): void
    {
        $name = $key->expression;

        if ($value instanceof NullValue) {
            $builder->whereNotNull($name);

            return;
        }

        $bound = ($key->bind)($value);

        if ($key->direction === SortDirection::Descending) {
            $builder->where($name, '<', $bound);
        } elseif ($key->notNull) {
            $builder->where($name, '>', $bound);
        } else {
            $builder->where(static fn (Builder $greater): Builder => $greater->where($name, '>', $bound)->orWhereNull($name));
        }
    }

    /**
     * The cursor after the row: its values of the order's columns and its entry.
     *
     * @param  list<ColumnOrder>  $order
     */
    private function cursor(TypeDefinition $type, array $order, TypeTableRow $row): TypeTableCursor
    {
        return new TypeTableCursor($row->entry, ...array_map(
            static fn (ColumnOrder $key): CursorKey => new CursorKey($key->column, self::valueOf($type, $row->fields, $key->column)),
            $order,
        ));
    }

    private static function valueOf(TypeDefinition $type, FieldValues $fields, string $column): FieldValue
    {
        $field = TypeTableQuery::fieldOf($type, $column);
        $value = $field->namespace instanceof FieldNamespace ? $fields->extension($field->namespace)?->get($field->handle) : $fields->own->get($field->handle);

        return $value ?? new NullValue;
    }

    private function notNull(TypeDefinition $type, string $column): bool
    {
        $definition = TypeTableQuery::fieldOf($type, $column)->column;

        return $definition instanceof ColumnDefinition && $definition->notNull;
    }

    private static function column(string $column): string
    {
        return self::TABLE.'.'.$column;
    }
}
