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
 * type.describe as the person, node.list as the person, the nodes a new entry's home and an
 * entry's placements may be below, and, for an entry, the entry as entry.get reads it and its
 * preview as entry.preview reads it, the draft or the revision the address's query parameter
 * `revision` names, each as the person. A save runs entry.create or entry.revise, and the publish
 * panel placement.create, entry.publish, variant.release, placement.set_window and
 * entry.unpublish, through the panel's Inertia profile, so the page reads nothing a command does
 * not check again. Each read goes through the query pipeline, so the same
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

    /** The query the page reads the nodes a new entry's home and an entry's placements may be below with. */
    public const string NODES_QUERY = 'node.list';

    /** The query the page of an entry reads the preview of its draft, or of a revision, with. */
    public const string PREVIEW_QUERY = 'entry.preview';

    /** The query parameter that names the revision the page of an entry previews; missing is the draft. */
    public const string REVISION_PARAMETER = 'revision';

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
     * The query the page reads the nodes with, node.list version 1.
     */
    public static function nodesQuery(): CommandRef
    {
        return new CommandRef(new CommandName(self::NODES_QUERY), 1);
    }

    /**
     * The query the page of an entry reads its preview with, entry.preview version 1.
     */
    public static function previewQuery(): CommandRef
    {
        return new CommandRef(new CommandName(self::PREVIEW_QUERY), 1);
    }
}
