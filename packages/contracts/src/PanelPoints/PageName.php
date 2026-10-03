<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\PanelPoints;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The name of a page of the panel that renders points, such as `shell`, `account.me` or
 * `command.form`: one or more segments separated by dots, each a lowercase letter followed by
 * lowercase letters and digits, with single hyphens between them, and at most 64 characters in
 * all.
 */
#[Experimental]
final readonly class PageName
{
    public const string PATTERN = '/\A[a-z][a-z0-9]*(?:-[a-z0-9]+)*(?:\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*)*\z/';

    public const int MAX_LENGTH = 64;

    /**
     * @throws InvalidPanelPoint
     */
    public function __construct(public string $value)
    {
        if (strlen($value) > self::MAX_LENGTH || preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidPanelPoint::because(sprintf(
                'The panel page name "%s" is not dot-separated segments of lowercase letters, digits and single hyphens, each starting with a letter, of at most %d characters, such as "account.me".',
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
