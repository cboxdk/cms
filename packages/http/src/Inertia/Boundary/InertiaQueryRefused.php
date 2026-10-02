<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Inertia\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use RuntimeException;

/**
 * An Inertia page visit whose query document the query's codec refused, so nothing ran. It carries
 * the catalog error the page shows, at its path below `query`.
 */
#[Internal]
final class InertiaQueryRefused extends RuntimeException
{
    private function __construct(public readonly CatalogError $error, DecodingFailed $previous)
    {
        parent::__construct($error->message, 0, $previous);
    }

    public static function document(DecodingFailed $failed, FieldPath $path): self
    {
        return new self(new CatalogError($failed->errorCode, $path, $failed->reason), $failed);
    }
}
