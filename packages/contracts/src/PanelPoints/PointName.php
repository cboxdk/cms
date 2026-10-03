<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\PanelPoints;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The name of a panel point without its version, such as `account.me.sections` or
 * `grants.list.row-actions`: at least two segments separated by dots, each a lowercase letter
 * followed by lowercase letters and digits, with single hyphens between them, and at most 64
 * characters in all. The first segments usually name the point's page.
 */
#[Experimental]
final readonly class PointName
{
    public const string PATTERN = '/\A[a-z][a-z0-9]*(?:-[a-z0-9]+)*(?:\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*)+\z/';

    public const int MAX_LENGTH = 64;

    /**
     * @throws InvalidPanelPoint
     */
    public function __construct(public string $value)
    {
        if (strlen($value) > self::MAX_LENGTH || preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidPanelPoint::because(sprintf(
                'The panel point name "%s" is not at least two dot-separated segments of lowercase letters, digits and single hyphens, each starting with a letter, of at most %d characters, such as "account.me.sections".',
                $value,
                self::MAX_LENGTH,
            ));
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
