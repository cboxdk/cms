<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Delivery\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Delivery\Domain\Dto\DeliveryRequest;
use Cbox\Cms\Http\Credentials\Boundary\BearerCredential;
use Illuminate\Http\Request;

/**
 * Reads a request of the delivery API's resolve (PRD 8.10 point 8): the known query parameters
 * `site`, `locale`, `path` and `debug`, each as the one string it holds or null, and every other
 * parameter left out, so no unknown parameter changes the answer. A parameter given twice, or as an
 * array, reads as malformed. The Bearer credential is read only for a request that asks for the
 * explanation; every other answer is the anonymous one, whatever the request carries.
 */
#[Internal]
final readonly class DeliveryInput
{
    /** What a parameter given as anything but one string reads as: never a valid value. */
    private const string MALFORMED = "\0";

    public static function request(Request $request): DeliveryRequest
    {
        $debug = self::parameter($request, 'debug');

        return new DeliveryRequest(
            self::parameter($request, 'site'),
            self::parameter($request, 'locale'),
            self::parameter($request, 'path'),
            $debug,
            $debug === null ? null : BearerCredential::of($request),
        );
    }

    private static function parameter(Request $request, string $name): ?string
    {
        $value = $request->query->all()[$name] ?? null;

        return match (true) {
            $value === null => null,
            is_string($value) => $value,
            default => self::MALFORMED,
        };
    }
}
