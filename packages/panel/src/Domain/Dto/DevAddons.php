<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;
use InvalidArgumentException;

/**
 * The addons whose panel UI this process loads from a dev server instead of their bundle (PRD
 * 13.4), as CBOX_CMS_PANEL_DEV_ADDONS names them, sorted by namespace; none unless the variable is
 * set. Only a local application has any: the panel's provider refuses to boot a process that
 * serves HTTP with one elsewhere, and cms:doctor's panel.dev_server reports it.
 */
#[Internal]
final readonly class DevAddons
{
    /** The environment variable that names the dev servers. */
    public const string VARIABLE = 'CBOX_CMS_PANEL_DEV_ADDONS';

    /** @var list<DevServer> */
    public array $servers;

    /**
     * @param  list<DevServer>  $servers
     *
     * @throws InvalidArgumentException when an addon has two servers
     */
    public function __construct(array $servers = [])
    {
        $byAddon = [];

        foreach ($servers as $server) {
            if (isset($byAddon[$server->addon->value])) {
                throw new InvalidArgumentException("The addon {$server->addon->value} has two dev servers.");
            }

            $byAddon[$server->addon->value] = $server;
        }

        ksort($byAddon, SORT_STRING);
        $this->servers = array_values($byAddon);
    }

    public static function none(): self
    {
        return new self;
    }

    public function any(): bool
    {
        return $this->servers !== [];
    }

    public function of(AddonNamespace $addon): ?DevServer
    {
        foreach ($this->servers as $server) {
            if ($server->addon->equals($addon)) {
                return $server;
            }
        }

        return null;
    }
}
