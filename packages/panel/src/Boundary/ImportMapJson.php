<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Panel\Domain\Dto\ImportMap;
use JsonException;

/**
 * Writes a panel page's import map (ImportMap) as the text of its `<script type="importmap">`:
 * `imports`, `scopes` and `integrity`, each an object even when it is empty, with slashes as they
 * are and `<`, `>` and `&` escaped, so nothing in the map can end the script element. The refused
 * modules are written only through the scopes that name them.
 */
#[Internal]
final readonly class ImportMapJson
{
    private function __construct() {}

    /**
     * @throws JsonException
     */
    public static function encode(ImportMap $map): string
    {
        return json_encode(
            ['imports' => $map->imports, 'scopes' => $map->scopes, 'integrity' => $map->integrity],
            JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP,
        );
    }
}
