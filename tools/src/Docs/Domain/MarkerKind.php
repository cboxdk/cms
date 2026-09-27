<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

/**
 * The HTML comments a page uses to declare what it documents and what it embeds.
 */
enum MarkerKind: string
{
    /** `<!-- extension-point: <FQCN or repo-relative schema path> -->`: the page documents it. */
    case ExtensionPoint = 'extension-point';

    /** `<!-- example: <repo-relative path> -->`: the fenced block below is that *Test.php. */
    case Example = 'example';

    /** `<!-- example-file: <repo-relative path> -->`: the fenced block below is that support file or fixture. */
    case ExampleFile = 'example-file';

    public function embeds(): bool
    {
        return $this !== self::ExtensionPoint;
    }
}
