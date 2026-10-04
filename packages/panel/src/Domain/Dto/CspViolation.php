<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;
use InvalidArgumentException;

/**
 * One violation of the panel's Content-Security-Policy a browser reported (GUARDRAILS 5, 6): the
 * directive that was broken, and the addon whose files the blocked resource or the script that
 * loaded it belongs to, or null for the panel's own. Nothing else of the report is kept: the
 * telemetry counts violations by directive and addon, ids only (invariant 10).
 */
#[Internal]
final readonly class CspViolation
{
    /** A directive's name: lower-case words joined by hyphens, as the CSP specification names them. */
    public const string DIRECTIVE_PATTERN = '/\A[a-z]+(?:-[a-z]+)*\z/';

    /**
     * @throws InvalidArgumentException when the directive is not a directive's name
     */
    public function __construct(
        public string $directive,
        public ?AddonNamespace $addon,
    ) {
        if (preg_match(self::DIRECTIVE_PATTERN, $directive) !== 1) {
            throw new InvalidArgumentException("\"{$directive}\" is not the name of a Content-Security-Policy directive.");
        }
    }
}
