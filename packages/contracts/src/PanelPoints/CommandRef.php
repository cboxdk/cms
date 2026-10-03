<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\PanelPoints;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\InvalidCommandName;

/**
 * One version of a command, written `<name>@<version>` such as `entry.create@1`, as a contribution
 * names the command forms it applies to.
 */
#[Experimental]
final readonly class CommandRef
{
    /**
     * @throws InvalidPanelPoint for a version below 1
     */
    public function __construct(public CommandName $name, public int $version)
    {
        if ($version < 1) {
            throw InvalidPanelPoint::because(sprintf('The command %s has version %d. Versions start at 1.', $name->value, $version));
        }
    }

    /**
     * Reads `<name>@<version>`, as toString() writes it.
     *
     * @throws InvalidPanelPoint
     */
    public static function fromString(string $value): self
    {
        if (preg_match('/\A(?<name>[^@]+)@(?<version>[1-9][0-9]{0,8})\z/', $value, $parts) !== 1) {
            throw InvalidPanelPoint::because(sprintf('"%s" is not a command and version: the command\'s name, "@" and its version from 1, such as "entry.create@1".', $value));
        }

        try {
            return new self(new CommandName($parts['name']), (int) $parts['version']);
        } catch (InvalidCommandName $invalid) {
            throw InvalidPanelPoint::because($invalid->getMessage(), $invalid);
        }
    }

    public function toString(): string
    {
        return $this->name->value.'@'.$this->version;
    }
}
