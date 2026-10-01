<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\TypeTables\Adapter;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
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
use Cbox\Cms\Contracts\TypeTables\SortDirection;
use Cbox\Cms\Contracts\TypeTables\TypeTableCursor;
use Cbox\Cms\Contracts\TypeTables\TypeTablePage;
use Cbox\Cms\Contracts\TypeTables\TypeTableQuery;
use Cbox\Cms\Contracts\TypeTables\TypeTableReader;
use Cbox\Cms\Contracts\TypeTables\TypeTableRow;
use Cbox\Cms\Core\Reads\Adapter\ConnectionQueryTransaction;
use Cbox\Cms\Core\Reads\Domain\Dto\AuditedRead;
use Cbox\Cms\Core\Reads\Domain\Dto\ReadAuditRecord;
use Cbox\Cms\Core\Reads\Domain\ReadableFields;
use Cbox\Cms\Core\Reads\Domain\ReadAudit;
use Cbox\Cms\Core\TypeTables\Boundary\TypeTableColumns;
use Cbox\Cms\Core\TypeTables\Domain\UnreadableTypeTable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
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

        $this->access($db, $builder, $actor, $access->regions);
        $direction = $this->order($builder, $query->order);

        if ($query->after instanceof TypeTableCursor) {
            $this->after($builder, $type, $query->order, $query->after, $direction);
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
     * Orders by the keys and then the entry id, and gives the entry id's direction.
     *
     * @param  list<ColumnOrder>  $order
     */
    private function order(Builder $builder, array $order): SortDirection
    {
        $direction = SortDirection::Ascending;

        foreach ($order as $key) {
            $builder->orderBy(self::column($key->column), $key->direction->value);
            $direction = $key->direction;
        }

        $builder->orderBy(self::column('cms_entry_id'), $direction->value);

        return $direction;
    }

    /**
     * The keyset condition: the rows after the cursor's row in the order. For the keys k1 to kn and
     * the entry id, a row is after when, for some i, it equals the cursor on k1 to k(i-1) and is
     * after it on ki, with null as the greatest value. When the first key's value bounds the rest,
     * the condition starts with that bound, so the index scan starts at the cursor.
     *
     * @param  list<ColumnOrder>  $order
     */
    private function after(Builder $builder, TypeDefinition $type, array $order, TypeTableCursor $cursor, SortDirection $entryDirection): void
    {
        /** @var list<array{string, SortDirection, FieldValue, bool}> $keys */
        $keys = [];

        foreach ($order as $index => $key) {
            $keys[] = [$key->column, $key->direction, $cursor->keys[$index]->value, $this->notNull($type, $key->column)];
        }

        if ($keys !== []) {
            $this->bound($builder, ...$keys[0]);
        }

        $entry = $cursor->entry->toString();

        $builder->where(static function (Builder $after) use ($keys, $entry, $entryDirection): void {
            foreach ($keys as $position => [, $direction, $value]) {
                if ($direction === SortDirection::Ascending && $value instanceof NullValue) {
                    continue;
                }

                $after->orWhere(static function (Builder $branch) use ($keys, $position): void {
                    self::equalBefore($branch, $keys, $position);
                    self::beyond($branch, ...$keys[$position]);
                });
            }

            $after->orWhere(static function (Builder $branch) use ($keys, $entry, $entryDirection): void {
                self::equalBefore($branch, $keys, count($keys));
                $branch->where(self::column('cms_entry_id'), $entryDirection === SortDirection::Descending ? '<' : '>', $entry);
            });
        });
    }

    /**
     * The bound the first key's value sets on the rows after the cursor, where one holds without
     * an OR: at or below it descending, where nulls came first; at or above it ascending on a
     * column that holds no null; and null ascending after a null.
     */
    private function bound(Builder $builder, string $column, SortDirection $direction, FieldValue $value, bool $notNull): void
    {
        if ($value instanceof NullValue) {
            if ($direction === SortDirection::Ascending) {
                $builder->whereNull(self::column($column));
            }

            return;
        }

        if ($direction === SortDirection::Descending) {
            $builder->where(self::column($column), '<=', TypeTableColumns::binding($column, $value));
        } elseif ($notNull) {
            $builder->where(self::column($column), '>=', TypeTableColumns::binding($column, $value));
        }
    }

    /**
     * The rows equal to the cursor on the keys before the position.
     *
     * @param  list<array{string, SortDirection, FieldValue, bool}>  $keys
     */
    private static function equalBefore(Builder $builder, array $keys, int $position): void
    {
        foreach (array_slice($keys, 0, $position) as [$column, , $value]) {
            if ($value instanceof NullValue) {
                $builder->whereNull(self::column($column));
            } else {
                $builder->where(self::column($column), '=', TypeTableColumns::binding($column, $value));
            }
        }
    }

    /**
     * The rows after the value on one key, null being the greatest value.
     */
    private static function beyond(Builder $builder, string $column, SortDirection $direction, FieldValue $value, bool $notNull): void
    {
        $name = self::column($column);

        if ($value instanceof NullValue) {
            $builder->whereNotNull($name);

            return;
        }

        $bound = TypeTableColumns::binding($column, $value);

        if ($direction === SortDirection::Descending) {
            $builder->where($name, '<', $bound);
        } elseif ($notNull) {
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
