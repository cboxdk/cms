<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\NullValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Schema\ColumnDefinition;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Contracts\Schema\TypeCapabilities;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Contracts\TypeTables\ColumnFilter;
use Cbox\Cms\Contracts\TypeTables\FilterOperator;
use Cbox\Cms\Contracts\TypeTables\InvalidTypeTableQuery;
use Cbox\Cms\Contracts\TypeTables\TypeTablePage;
use Cbox\Cms\Contracts\TypeTables\TypeTableQuery;
use Cbox\Cms\Contracts\TypeTables\TypeTableReader;
use Cbox\Cms\Contracts\TypeTables\TypeTableRow;
use Cbox\Cms\Core\Tests\TypeTables\PostgresTypeTables;
use Cbox\Cms\Core\TypeTables\Adapter\PostgresTypeTableReader;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\TypeTables\TypeTableSeed;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;

/*
 * The TypeTableReader on Postgres gives a page's rows with the fields the context may read, and
 * writes the read audit of the sensitive fields it gives an actor (PRD 6.2, 12.2, 12.12), in the
 * caller's read transaction, under the query name type_tables.page version 1: the path the
 * generated query builders read through, which does not run the query pipeline.
 */

const AUDITED_PAGE_TYPE = 'probe:record';
const AUDITED_PAGE_FIRST = '0192a0c0-0000-7000-8000-0000000004e1';
const AUDITED_PAGE_SECOND = '0192a0c0-0000-7000-8000-0000000004e2';
const AUDITED_PAGE_NODE = '0192a0c000007000800000000000040a';

afterEach(function (): void {
    PostgresTypeTables::drop(auditedPageType());
    DB::purge(StorageTables::SUPERUSER);
});

function auditedPageType(): TypeDefinition
{
    $field = static fn (string $handle, ClassificationAccess $classification, bool $agents, bool $filterable = false): FieldDefinition => new FieldDefinition(
        null,
        new FieldHandle($handle),
        'text',
        $classification,
        $agents,
        false,
        false,
        $filterable,
        false,
        new ColumnDefinition($handle, 'text', false, []),
    );

    return new TypeDefinition(
        TypeId::fromString('0192a0c0-0000-7000-8000-00000000f004'),
        new TypeName(AUDITED_PAGE_TYPE),
        1,
        new TypeCapabilities(History::Full, Stages::DraftRelease, Localization::None, false),
        [],
        [
            $field('label', ClassificationAccess::Public, true),
            $field('remark', ClassificationAccess::Internal, true),
            $field('diagnosis', ClassificationAccess::Sensitive, false, filterable: true),
        ],
    );
}

/**
 * The reader over the type's table, with two rows the actor owns, and the audit at the tables'
 * clock, so its partition is covered.
 */
function auditedPageReader(ReadAuditTables $tables): TypeTableReader
{
    $type = auditedPageType();
    $fields = static fn (string $label, ?string $diagnosis): FieldValues => new FieldValues(new FieldMap(
        new NamedValue(new FieldHandle('label'), new TextValue($label)),
        new NamedValue(new FieldHandle('remark'), new TextValue('seen')),
        new NamedValue(new FieldHandle('diagnosis'), $diagnosis === null ? new NullValue : new TextValue($diagnosis)),
    ));

    PostgresTypeTables::create($type);
    PostgresTypeTables::seed(
        $type,
        new TypeTableSeed(EntryId::fromString(AUDITED_PAGE_FIRST), new NodePath(AUDITED_PAGE_NODE), $fields('first', 'held back'), $tables->actor),
        new TypeTableSeed(EntryId::fromString(AUDITED_PAGE_SECOND), new NodePath(AUDITED_PAGE_NODE), $fields('second', null), $tables->actor),
    );

    return PostgresTypeTables::inContext(new PostgresTypeTableReader(app(ConnectionResolverInterface::class), new FakeTypeCatalog($type), $tables->audit()));
}

function auditedPageContext(ReadAuditTables $tables, ClassificationAccess $access): AccessContext
{
    return new AccessContext(new ActorPrincipal($tables->actor, [], IssuerKind::Service, ClassificationAccess::Sensitive), [], $access);
}

/**
 * The handles of each row's fields, by row.
 *
 * @param  list<TypeTableRow>  $rows
 * @return list<list<string>>
 */
function auditedPageHandles(array $rows): array
{
    return array_map(static fn (TypeTableRow $row): array => array_map(static fn (NamedValue $field): string => $field->handle->value, $row->fields->own->fields), $rows);
}

it('writes the read audit of the sensitive fields a page gives an actor, one row per entry, without the values', function (): void {
    $tables = ReadAuditTables::at();
    $page = auditedPageReader($tables)->page(new TypeTableQuery(new TypeName(AUDITED_PAGE_TYPE), VariantKey::shared()), auditedPageContext($tables, ClassificationAccess::Sensitive));
    $rows = $tables->rows();

    expect(auditedPageHandles($page->rows))->toBe([['diagnosis', 'label', 'remark'], ['diagnosis', 'label', 'remark']])
        ->and($rows)->toHaveCount(2)
        ->and(array_map(static fn (string $row): string => implode(' | ', array_slice(explode(' | ', $row), 1, 6)), $rows))->toBe([
            sprintf('%s | %s | %s | 1 | sensitive | diagnosis', AUDITED_PAGE_FIRST, $tables->actor->toString(), PostgresTypeTableReader::AUDIT_QUERY),
            sprintf('%s | %s | %s | 1 | sensitive | diagnosis', AUDITED_PAGE_SECOND, $tables->actor->toString(), PostgresTypeTableReader::AUDIT_QUERY),
        ])
        ->and(array_unique(array_map(static fn (string $row): string => explode(' | ', $row)[0], $rows)))->toHaveCount(1)
        ->and(implode("\n", $rows))->not->toContain('held back');
});

it('leaves the sensitive field out of a page for a context below it, and writes no read audit', function (): void {
    $tables = ReadAuditTables::at();
    $page = auditedPageReader($tables)->page(new TypeTableQuery(new TypeName(AUDITED_PAGE_TYPE), VariantKey::shared()), auditedPageContext($tables, ClassificationAccess::Personal));

    expect(auditedPageHandles($page->rows))->toBe([['label', 'remark'], ['label', 'remark']])
        ->and($tables->rows())->toBe([]);
});

it('refuses a filter on the sensitive field for a context below it, so the filter tells nothing about its values', function (): void {
    $tables = ReadAuditTables::at();
    $query = new TypeTableQuery(new TypeName(AUDITED_PAGE_TYPE), VariantKey::shared(), [new ColumnFilter('diagnosis', FilterOperator::Eq, new TextValue('held back'))]);

    expect(fn (): TypeTablePage => auditedPageReader($tables)->page($query, auditedPageContext($tables, ClassificationAccess::Internal)))
        ->toThrow(InvalidTypeTableQuery::class, 'is not readable')
        ->and($tables->rows())->toBe([]);
});
