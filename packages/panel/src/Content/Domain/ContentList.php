<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Content\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\PageName;

/**
 * The content list (PRD 5.4, 13.4): the panel's page `content.list`, which lists the entries the
 * person reaches, newest first, across every type at `<prefix>/content` and of one type at
 * `<prefix>/content/<owner>:<handle>`, read with the query entry.list through the query pipeline as
 * the person, a keyset page of LIMIT entries at a time, filtered by the release state of the shared
 * variant the address names as `?state=<state>`. Each row shows the entry's type, the title field
 * its type's editor descriptor names, its state and its last change. The navigation's Content
 * section and the type of each row come from the prop `types` every page behind the login shares,
 * the read of type.list (ContentTypes).
 */
#[Internal]
final readonly class ContentList
{
    /** The page's name, which a panel point names as its page. */
    public const string PAGE = 'content.list';

    /** The query the page's props come from. */
    public const string QUERY = 'entry.list';

    public const int QUERY_VERSION = 1;

    /** The page's path below the panel's prefix, the list across every type. */
    public const string PATH = 'content';

    /** The path of the list of one type, below PATH; the type's name is its segment. */
    public const string TYPE_PATH = self::PATH.'/{type}';

    /** What the address of the list of one type takes as its type: the form of a TypeName. */
    public const string TYPE_SEGMENT = '[a-z][a-z0-9]{0,19}:[a-z][a-z0-9_]{0,62}';

    /** The query parameter of the release state the list is filtered by. */
    public const string STATE = 'state';

    /** How many entries a page of the list holds. */
    public const int LIMIT = 25;

    private function __construct() {}

    public static function page(): PageName
    {
        return new PageName(self::PAGE);
    }

    /**
     * The query the page reads, entry.list version 1.
     */
    public static function query(): CommandRef
    {
        return new CommandRef(new CommandName(self::QUERY), self::QUERY_VERSION);
    }
}
