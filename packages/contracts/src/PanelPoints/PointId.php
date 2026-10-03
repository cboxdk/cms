<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\PanelPoints;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The identity of a panel point, its name and version, written `<name>@<version>` such as
 * `account.me.sections@1`. A breaking change to a point's props is a new version, and both
 * versions are points of their own.
 */
#[Experimental]
final readonly class PointId
{
    /**
     * @throws InvalidPanelPoint for a version below 1
     */
    public function __construct(public PointName $name, public int $version)
    {
        if ($version < 1) {
            throw InvalidPanelPoint::because(sprintf('The panel point %s has version %d. Versions start at 1.', $name->value, $version));
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
            throw InvalidPanelPoint::because(sprintf('"%s" is not a panel point id: the point\'s name, "@" and its version from 1, such as "account.me.sections@1".', $value));
        }

        return new self(new PointName($parts['name']), (int) $parts['version']);
    }

    public function toString(): string
    {
        return $this->name->value.'@'.$this->version;
    }

    public function equals(self $other): bool
    {
        return $this->name->equals($other->name) && $this->version === $other->version;
    }
}
