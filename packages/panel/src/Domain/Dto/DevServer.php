<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;
use InvalidArgumentException;

/**
 * The Vite dev server an addon's panel UI is loaded from while it is developed (PRD 13.4): the
 * addon's namespace and the server's origin, one of the loopback hosts over plain HTTP, as
 * CBOX_CMS_PANEL_DEV_ADDONS names it. The panel's import map then maps the addon's entry to
 * ENTRY on the server, which the addon's build plugin serves, and each shared module as the
 * server's import analysis writes it, below SHARED_PREFIX, to the panel's own copy; the page
 * loads the server's client for hot module replacement, and the policy lets the page reach the
 * server and its websocket. It works only in the local environment.
 */
#[Internal]
final readonly class DevServer
{
    /** The path the addon's build plugin serves the entry module at, @cboxdk/cms-panel/vite's DEV_ENTRY. */
    public const string ENTRY = '/@cms-panel-addon/entry';

    /** The path the server's import analysis puts before a shared module's specifier, the plugin's DEV_SHARED_PREFIX. */
    public const string SHARED_PREFIX = '/@id/';

    /** The server's client, which the page loads for hot module replacement. */
    public const string CLIENT = '/@vite/client';

    /** An origin of a loopback host over plain HTTP, with an optional port, and nothing after it. */
    public const string ORIGIN_PATTERN = '~\Ahttp://(?:localhost|127\.0\.0\.1|\[::1\])(?::[1-9][0-9]{0,4})?\z~';

    /**
     * @throws InvalidArgumentException when the origin is not a loopback origin over plain HTTP
     */
    public function __construct(
        public AddonNamespace $addon,
        public string $origin,
    ) {
        if (preg_match(self::ORIGIN_PATTERN, $origin) !== 1) {
            throw new InvalidArgumentException("The dev server {$origin} of addon {$addon->value} is not an origin of localhost, 127.0.0.1 or [::1] over http, such as http://localhost:5174.");
        }
    }

    /**
     * The URL of the addon's entry module on the server.
     */
    public function entryUrl(): string
    {
        return $this->origin.self::ENTRY;
    }

    /**
     * The URL the server's import analysis gives an import of a shared module.
     */
    public function sharedUrl(string $specifier): string
    {
        return $this->origin.self::SHARED_PREFIX.$specifier;
    }

    /**
     * The URL of the server's client.
     */
    public function clientUrl(): string
    {
        return $this->origin.self::CLIENT;
    }

    /**
     * The origin of the server's websocket, which its client connects to.
     */
    public function websocket(): string
    {
        return 'ws://'.substr($this->origin, strlen('http://'));
    }
}
