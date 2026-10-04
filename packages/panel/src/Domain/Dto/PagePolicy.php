<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Panel\Domain\CspNonce;
use InvalidArgumentException;

/**
 * What one panel response's Content-Security-Policy is made from (GUARDRAILS 6): the response's
 * nonce, for its styles; the SHA-256 of each inline script the page carries, its import map, for
 * its scripts, which the policy names instead of a nonce so that a script's import() stays on the
 * panel's origin (D6); the path the browser reports a violation to, or null when the panel's
 * report route is not registered; and the dev servers of the addons the page loads, each an
 * origin the policy lets the page load scripts from and connect to.
 */
#[Internal]
final readonly class PagePolicy
{
    /** The base64 of a SHA-256. */
    public const string HASH_PATTERN = '~\A[A-Za-z0-9+/]{43}=\z~';

    /**
     * @param  list<string>  $inlineScripts  the SHA-256 of each inline script, in base64
     * @param  list<DevServer>  $devServers
     *
     * @throws InvalidArgumentException when a hash is not a SHA-256 in base64, or the report path is not a path
     */
    public function __construct(
        public CspNonce $nonce,
        public array $inlineScripts = [],
        public ?string $reportPath = null,
        public array $devServers = [],
    ) {
        foreach ($inlineScripts as $hash) {
            if (preg_match(self::HASH_PATTERN, $hash) !== 1) {
                throw new InvalidArgumentException("The inline script hash {$hash} is not a SHA-256 in base64.");
            }
        }

        if ($reportPath !== null && preg_match(ImportMap::PATH_PATTERN, $reportPath) !== 1) {
            throw new InvalidArgumentException("The report path {$reportPath} is not a path on the panel's origin.");
        }
    }

    /**
     * The hash source of an inline script's text, `sha256-<base64>`, as the policy names it.
     */
    public static function hashOf(string $script): string
    {
        return base64_encode(hash('sha256', $script, true));
    }
}
