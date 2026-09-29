<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Credentials\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Illuminate\Http\Request;

/**
 * The credential an HTTP request carries in its Authorization header (PRD 5.16): the token of the
 * Bearer scheme, whose name is compared without case, as RFC 9110 says. A request without the
 * header carries none. A header in another form is handed on whole, so the CredentialVerifier
 * refuses it as credential_malformed instead of the call running as the anonymous principal.
 */
#[Internal]
final readonly class BearerCredential
{
    public const string HEADER = 'Authorization';

    public static function of(Request $request): ?TransportCredential
    {
        $header = $request->headers->get(self::HEADER);

        if ($header === null || $header === '') {
            return null;
        }

        return preg_match('/\ABearer +(\S+)\z/i', $header, $match) === 1
            ? new TransportCredential($match[1])
            : new TransportCredential($header);
    }
}
