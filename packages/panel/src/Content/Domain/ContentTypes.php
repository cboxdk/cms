<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Content\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;

/**
 * The types of the installation as every page behind the login knows them (PRD 11.12, 13.4): the
 * prop PROP, the read of type.list as the person, from which the shell builds the Content section
 * of the navigation, an entry to the content list of each type the person may see, and the content
 * list shows each entry's type and title field. The kernel knows no type by name (GUARDRAILS 2.4),
 * so the panel never lists a type the installation's editor descriptors do not have.
 */
#[Internal]
final readonly class ContentTypes
{
    /** The shared prop. */
    public const string PROP = 'types';

    /** The query the prop is the read of, at its version. */
    public const string QUERY = 'type.list';

    public const int VERSION = 1;

    private function __construct() {}

    public static function query(): CommandRef
    {
        return new CommandRef(new CommandName(self::QUERY), self::VERSION);
    }
}
