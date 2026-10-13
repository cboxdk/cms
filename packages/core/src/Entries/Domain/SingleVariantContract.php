<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\TypeDefinition;

/**
 * The single-variant contract of the entry commands of version 1 (PRD 10.1): entry.create,
 * entry.revise, variant.release, entry.publish and entry.unpublish write and release the shared
 * variant alone, the one variant of a type with localization none. A type with localization
 * variants has a variant per language beside the shared one, and a write of its shared variant
 * alone would leave an entry without a language, so version 1 refuses such a type for good, with
 * validation_failed naming version 2 of the command, which writes the languages' variants.
 */
#[Internal]
final readonly class SingleVariantContract
{
    /**
     * The refusal of the command for the type, or null when the type has localization none or is
     * not known, which the kernel refuses on its own.
     *
     * @param  string  $command  the name of the command, such as `entry.create`
     * @param  string  $path  the path of the command's field that names the entry or the type
     */
    public static function refusal(string $command, string $path, ?TypeDefinition $type): ?CatalogError
    {
        if (! $type instanceof TypeDefinition || $type->capabilities->localization !== Localization::Variants) {
            return null;
        }

        return new CatalogError(ErrorCode::ValidationFailed, new FieldPath($path), sprintf(
            '%s version 1 writes the shared variant of a type with localization none. The type %s has localization variants, whose languages are variants of the entry beside the shared one (PRD 10.1), which %s version 2 writes; version 1 never writes such a type.',
            $command,
            $type->name->value,
            $command,
        ));
    }
}
