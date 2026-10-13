<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Entries\Boundary\StoredContent;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\JoinClause;
use LogicException;
use UnexpectedValueException;

/**
 * The current content of the variants of entries of one type, for the writers that put a
 * language's row in the type table (PRD 10.1, 11.6): the row holds the language's fields and the
 * shared variant's, so a write of a language's variant needs the shared variant's content, and a
 * release of the shared variant the published content of every released language. Each read is
 * one statement for a run of entries, whatever the number of languages (GUARDRAILS 4.1), on the
 * caller's connection under its actor context, inside the command transaction, on the write PDO.
 *
 * drafts() gives the shared variant's current content: the payload of the head's draft revision for
 * a type with full history, the head snapshot otherwise. released() gives the published content of
 * the released heads among the variants asked for. A payload under another schema version than the
 * type's cannot be read with the type's fields, so the write that needs it throws; upcasters are
 * block B3.
 */
#[Internal]
final readonly class VariantPayloads
{
    public const string RELEASED = 'released';

    public function __construct(private ConnectionInterface $db) {}

    /**
     * The current draft content of the shared variant of each entry that has a shared head, by
     * entry id.
     *
     * @param  non-empty-list<string>  $entries
     * @return array<string, FieldValues>
     *
     * @throws LogicException when a payload was written under another schema version than the type's
     */
    public function drafts(TypeDefinition $type, array $entries): array
    {
        $rows = $this->db->table('variant_heads', 'h')
            ->leftJoin('revisions as r', 'r.revision_id', '=', 'h.draft_revision_id')
            ->leftJoin('revision_payloads as p', static function (JoinClause $join): void {
                $join->on('p.revision_id', '=', 'r.revision_id')->on('p.kind', '=', 'r.kind');
            })
            ->leftJoin('head_snapshots as s', static function (JoinClause $join): void {
                $join->on('s.entry_id', '=', 'h.entry_id')->on('s.variant', '=', 'h.variant');
            })
            ->whereIn('h.entry_id', $entries)
            ->where('h.variant', VariantKey::SHARED)
            ->useWritePdo()
            ->get(['h.entry_id', 'h.variant', 'r.schema_version as revision_schema', 'p.content as revision_content', 's.schema_version as snapshot_schema', 's.content as snapshot_content']);
        $drafts = [];

        foreach ($rows as $row) {
            $content = $this->text($row, 'revision_content') ?? $this->text($row, 'snapshot_content');
            $schema = $this->integer($row, 'revision_schema') ?? $this->integer($row, 'snapshot_schema');
            $drafts[$this->entry($row)] = $this->fields($type, $this->entry($row), VariantKey::SHARED, $schema, $content);
        }

        return $drafts;
    }

    /**
     * The published content of each released head of the entries among the variants, by entry id
     * and variant key.
     *
     * @param  non-empty-list<string>  $entries
     * @param  list<string>|null  $variants  the variant keys to read; every variant when null
     * @return array<string, array<string, FieldValues>>
     *
     * @throws LogicException when a payload was written under another schema version than the type's
     */
    public function released(TypeDefinition $type, array $entries, ?array $variants = null): array
    {
        $query = $this->db->table('variant_heads', 'h')
            ->join('revisions as r', 'r.revision_id', '=', 'h.published_revision_id')
            ->join('revision_payloads as p', static function (JoinClause $join): void {
                $join->on('p.revision_id', '=', 'r.revision_id')->on('p.kind', '=', 'r.kind');
            })
            ->whereIn('h.entry_id', $entries)
            ->where('h.release_state', self::RELEASED);

        if ($variants !== null) {
            $query->whereIn('h.variant', $variants);
        }

        $released = [];

        foreach ($query->useWritePdo()->get(['h.entry_id', 'h.variant', 'r.schema_version', 'p.content']) as $row) {
            $variant = $this->text($row, 'variant') ?? throw new UnexpectedValueException('A head has a variant.');
            $released[$this->entry($row)][$variant] = $this->fields($type, $this->entry($row), $variant, $this->integer($row, 'schema_version'), $this->text($row, 'content'));
        }

        return $released;
    }

    /**
     * @throws LogicException when the payload is missing or under another schema version than the type's
     */
    private function fields(TypeDefinition $type, string $entry, string $variant, ?int $schema, ?string $content): FieldValues
    {
        if ($content === null || $schema === null) {
            throw new LogicException(sprintf('The head of the variant %s of the entry %s has no content to write the type table\'s row from.', $variant, $entry));
        }

        if ($schema !== $type->version) {
            throw new LogicException(sprintf(
                'The content of the variant %s of the entry %s was written under schema version %d of %s, and this installation writes version %d, so a row of its language cannot be written from it until the variant is saved under the current version.',
                $variant,
                $entry,
                $schema,
                $type->name->value,
                $type->version,
            ));
        }

        return StoredContent::fields($type, $content);
    }

    private function entry(object $row): string
    {
        return $this->text($row, 'entry_id') ?? throw new UnexpectedValueException('A head has an entry id.');
    }

    private function text(object $row, string $column): ?string
    {
        $value = property_exists($row, $column) ? $row->{$column} : null;

        return $value === null || is_string($value) ? $value : throw new UnexpectedValueException(sprintf('The column %s of a head is text, got %s.', $column, get_debug_type($value)));
    }

    private function integer(object $row, string $column): ?int
    {
        $value = property_exists($row, $column) ? $row->{$column} : null;

        return $value === null || is_int($value) ? $value : throw new UnexpectedValueException(sprintf('The column %s of a head is an integer, got %s.', $column, get_debug_type($value)));
    }
}
