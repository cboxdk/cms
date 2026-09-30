<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Entries;

use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;

/**
 * Entries of the fixture article homed on EntryWorld::HOME beside the one a test writes, each with
 * the head of its shared variant and its draft row in the type table, written as the superuser, so
 * a test can show that a command on one entry costs the same queries however many share its node
 * (GUARDRAILS 4.1).
 */
final class EntryNeighbours
{
    /**
     * $count entries whose ids are numbered from $first.
     */
    public static function seed(TypeId $type, int $count, int $first): void
    {
        $entries = [];
        $heads = [];
        $rows = [];

        for ($number = $first; $number < $first + $count; $number++) {
            $id = sprintf('0192a0c0-0000-7000-9000-%012d', $number);
            $entries[] = ['id' => $id, 'type_id' => $type->toString(), 'home_node_id' => EntryWorld::HOME, 'owner_actor_id' => null, 'lifecycle' => 'active', 'version' => 1, 'created_at' => StorageTables::CREATED_AT];
            $heads[] = ['entry_id' => $id, 'variant' => 'shared', 'schema_version' => 1, 'release_state' => 'unreleased', 'version' => 1, 'created_at' => StorageTables::CREATED_AT];
            $rows[] = ['cms_entry_id' => $id, 'cms_locale' => 'shared', 'cms_stage' => 'draft', 'cms_home_node' => EntryWorld::HOME, 'fixture_title' => 'Neighbour '.$number, 'fixture_featured' => 'true'];
        }

        $superuser = StorageTables::superuser();
        $superuser->table('entries')->insert($entries);
        $superuser->table('variant_heads')->insert($heads);
        $superuser->table('app__fixture_article')->insert($rows);
    }
}
