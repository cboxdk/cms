<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Content\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\PageName;

/**
 * The entry editor (PRD 5.4, 11.12, 13.4): the panel's page `content.editor`, which creates an
 * entry of a type at `<prefix>/content/<owner>:<handle>/new` and edits one at
 * `<prefix>/content/<owner>:<handle>/<entry>`. It renders the type's editor descriptor, read with
 * type.describe as the person, and, for an entry, the entry as entry.get reads it as the person;
 * the page for a new entry also reads node.list as the person, the nodes its home may be. A save
 * runs entry.create or entry.revise through the panel's Inertia profile, so the page reads
 * nothing a command does not check again. Each read goes through the query pipeline, so the same
 * reads are on REST too (QueryPageParity).
 */
#[Internal]
final readonly class ContentEditor
{
    /** The page's name, which a panel point names as its page. */
    public const string PAGE = 'content.editor';

    /** The path of the page for a new entry of a type, below the panel's prefix. */
    public const string NEW_PATH = ContentList::TYPE_PATH.'/new';

    /** The path of the page of an entry, below the panel's prefix. */
    public const string ENTRY_PATH = ContentList::TYPE_PATH.'/{entry}';

    /** What the address of an entry takes as its entry: the form of a UUID, which EntryId checks. */
    public const string ENTRY_SEGMENT = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    /** The query the page of an entry reads the entry with. */
    public const string ENTRY_QUERY = 'entry.get';

    /** The query the page reads the type's editor descriptor with. */
    public const string TYPE_QUERY = 'type.describe';

    /** The query the page for a new entry reads the nodes its home may be with. */
    public const string NODES_QUERY = 'node.list';

    private function __construct() {}

    public static function page(): PageName
    {
        return new PageName(self::PAGE);
    }

    /**
     * The query the page of an entry reads the entry with, entry.get version 1.
     */
    public static function entryQuery(): CommandRef
    {
        return new CommandRef(new CommandName(self::ENTRY_QUERY), 1);
    }

    /**
     * The query the page reads the type with, type.describe version 1.
     */
    public static function typeQuery(): CommandRef
    {
        return new CommandRef(new CommandName(self::TYPE_QUERY), 1);
    }

    /**
     * The query the page for a new entry reads the nodes with, node.list version 1.
     */
    public static function nodesQuery(): CommandRef
    {
        return new CommandRef(new CommandName(self::NODES_QUERY), 1);
    }
}
