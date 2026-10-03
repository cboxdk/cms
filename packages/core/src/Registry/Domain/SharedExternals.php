<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The bare module specifiers an addon's panel bundle may import (PRD 13.4): the shared React
 * modules, which the panel's import map points at its own copy (js/panel/shared-modules.json), and
 * the panel's SDK, @cboxdk/cms-panel with its subpaths. Anything else a bundle imports by a bare
 * specifier is an unknown external, which cms:build refuses as registry_panel_bundle_invalid,
 * because the browser could not resolve it, or would resolve it to a second copy.
 */
#[Experimental]
final readonly class SharedExternals
{
    /** @var list<string> the keys of "shared" in js/panel/shared-modules.json */
    public const array REACT = ['react', 'react-dom', 'react-dom/client', 'react/jsx-runtime'];

    public const string SDK = '@cboxdk/cms-panel';

    public static function allows(string $specifier): bool
    {
        return in_array($specifier, self::REACT, true)
            || $specifier === self::SDK
            || preg_match('/\A'.preg_quote(self::SDK, '/').'\/[a-z0-9-]+(?:\/[a-z0-9-]+)*\z/', $specifier) === 1;
    }
}
