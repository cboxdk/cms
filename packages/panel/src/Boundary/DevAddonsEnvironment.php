<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Boundary;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Panel\Domain\Dto\DevAddons;
use Cbox\Cms\Panel\Domain\Dto\DevServer;
use Cbox\Cms\Panel\Domain\InvalidDevAddons;
use Illuminate\Support\Env;
use InvalidArgumentException;

/**
 * Reads CBOX_CMS_PANEL_DEV_ADDONS from the process's environment, never from the configuration,
 * which the processes may share through one cache (PRD 13.4): `<namespace>=<origin>` pairs joined
 * by commas, such as `approvals=http://localhost:5174`, each the Vite dev server an addon's panel
 * UI is loaded from. Unset or empty means no addon is.
 */
#[Internal]
final readonly class DevAddonsEnvironment
{
    public const string VARIABLE = DevAddons::VARIABLE;

    private function __construct() {}

    /**
     * The variable's text, or null when it is unset or empty.
     */
    public static function raw(): ?string
    {
        $value = Env::get(self::VARIABLE);

        if (in_array($value, [null, false, ''], true)) {
            return null;
        }

        return is_scalar($value) ? (string) $value : throw InvalidDevAddons::because(self::VARIABLE, 'it is not text.');
    }

    /**
     * @throws InvalidDevAddons
     */
    public static function read(): DevAddons
    {
        return self::parse(self::raw());
    }

    /**
     * The addons the text names.
     *
     * @throws InvalidDevAddons
     */
    public static function parse(?string $value): DevAddons
    {
        if ($value === null || trim($value) === '') {
            return DevAddons::none();
        }

        $servers = [];

        foreach (explode(',', $value) as $position => $pair) {
            $parts = explode('=', trim($pair), 2);

            if (count($parts) !== 2) {
                throw InvalidDevAddons::because(self::VARIABLE, sprintf('pair %d is not <namespace>=<origin>.', $position + 1));
            }

            try {
                $addon = new AddonNamespace(trim($parts[0]));
            } catch (InvalidArgumentException) {
                throw InvalidDevAddons::because(self::VARIABLE, sprintf('pair %d does not start with an addon namespace.', $position + 1));
            }

            try {
                $servers[] = new DevServer($addon, rtrim(trim($parts[1]), '/'));
            } catch (InvalidArgumentException) {
                throw InvalidDevAddons::because(self::VARIABLE, sprintf('the origin of addon %s in pair %d is not a loopback origin over http, such as http://localhost:5174.', $addon->value, $position + 1));
            }
        }

        try {
            return new DevAddons($servers);
        } catch (InvalidArgumentException $twice) {
            throw InvalidDevAddons::because(self::VARIABLE, $twice->getMessage());
        }
    }
}
