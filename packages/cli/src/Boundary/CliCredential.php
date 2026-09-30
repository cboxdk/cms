<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Boundary;

use Cbox\Cms\Cli\Domain\CliCallRefused;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Illuminate\Contracts\Config\Repository;

/**
 * The credential the CLI surface runs as (PRD 5.16, GUARDRAILS 2.1), the process's configured
 * service credential, `cbox-cms.cli.credential`:
 *
 *     'cli' => [
 *         'credential' => env('CBOX_CMS_CLI_CREDENTIAL'),   // a service credential's token, or null
 *     ],
 *
 * The actor of a write through cms:run is the actor of this credential, verified by the
 * CredentialVerifier like the Bearer token of a request; it never comes from an argument or an
 * option. Null, the default, is no credential, and every write is then refused as unauthorized.
 */
#[Internal]
final readonly class CliCredential
{
    public const string CONFIG_KEY = 'cbox-cms.cli.credential';

    /**
     * @throws CliCallRefused when the setting is neither null nor a non-empty string
     */
    public static function read(Repository $config): ?TransportCredential
    {
        $value = $config->get(self::CONFIG_KEY);

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || trim($value) === '') {
            throw CliCallRefused::config(sprintf(
                'The setting %s must be the token of a service credential, or null for none; it is %s.',
                self::CONFIG_KEY,
                is_string($value) ? 'an empty string' : get_debug_type($value),
            ));
        }

        return new TransportCredential($value);
    }
}
