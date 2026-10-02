<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The tables the core's migrations create (PRD 4, 6.5 invariants 1 and 13), with their LIST
 * partitions, and the partitions the partition manager makes below them, named
 * `<table>_p<digits>`. Only the kernel writes them: KernelTableWriteRule reports a write by name
 * anywhere else. A test in cboxdk/cms holds NAMES equal to the tables the migrations in
 * packages/core/database/migrations create.
 */
#[Internal]
final class KernelTables
{
    /**
     * @var list<string>
     */
    public const array NAMES = [
        'actor_profiles',
        'actors',
        'audit',
        'changeset_principals',
        'changeset_reason_texts',
        'changeset_register',
        'changesets',
        'entries',
        'event_cursors',
        'event_parked_aggregates',
        'events',
        'events_bulk',
        'events_interactive',
        'grants',
        'head_snapshots',
        'idempotency_keys',
        'mount_overrides',
        'node_routes',
        'nodes',
        'placement_generations',
        'placement_locales',
        'placements',
        'read_audit',
        'receipt_projections',
        'receipt_projections_evidence',
        'receipt_projections_standard',
        'receipts',
        'receipts_evidence',
        'receipts_standard',
        'release_log',
        'revision_payloads',
        'revision_payloads_draft',
        'revision_payloads_published',
        'revisions',
        'role_permissions',
        'roles',
        'service_credential_delegations',
        'service_credentials',
        'site_locales',
        'sites',
        'variant_heads',
    ];

    /**
     * The kernel table a name refers to, read without case, quotes or schema, or null. A managed
     * partition, `<table>_p<digits>`, refers to its table.
     */
    public static function of(string $name): ?string
    {
        $parts = explode('.', strtolower(str_replace('"', '', trim($name))));
        $table = end($parts);

        if (in_array($table, self::NAMES, true)) {
            return $table;
        }

        if (preg_match('/^(.+)_p\d+$/', $table, $match) === 1 && in_array($match[1], self::NAMES, true)) {
            return $match[1];
        }

        return null;
    }
}
