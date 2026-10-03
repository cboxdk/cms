<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * Something cms:build tells the installation without refusing to build (PRD 13.4): a contribution
 * to an experimental point, which may change in a minor release of the panel's API, and a
 * contribution to a deprecated point, with the release it goes in and its replacement. Each has a
 * code of the error catalog, and cms:build prints it before the counts.
 */
#[Experimental]
final readonly class BuildWarning
{
    /** A contribution goes to an experimental point the addon accepts. */
    public const string CODE_POINT_EXPERIMENTAL = 'registry_panel_point_experimental';

    /** A contribution goes to a deprecated point. */
    public const string CODE_POINT_DEPRECATED = 'registry_panel_point_deprecated';

    /** @var list<string> */
    public const array CODES = [self::CODE_POINT_DEPRECATED, self::CODE_POINT_EXPERIMENTAL];

    /**
     * @param  string  $code  one of CODES
     */
    public function __construct(
        public string $code,
        public string $message,
    ) {
        if (! in_array($code, self::CODES, true)) {
            throw new InvalidArgumentException(sprintf('"%s" is not the code of a build warning.', $code));
        }
    }

    public function describe(): string
    {
        return sprintf('[%s] %s', $this->code, $this->message);
    }
}
