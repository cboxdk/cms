<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Codecs;

use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Closure;
use LogicException;

/**
 * The DecodingFailed that a call throws, so a test can check its code, path and reason.
 */
final class Failures
{
    /**
     * @param  Closure(): mixed  $call
     */
    public static function of(Closure $call): DecodingFailed
    {
        try {
            $call();
        } catch (DecodingFailed $failure) {
            return $failure;
        }

        throw new LogicException('The call did not throw DecodingFailed.');
    }

    /**
     * The code, the path and the reason of the DecodingFailed a call throws.
     *
     * @param  Closure(): mixed  $call
     * @return array{string, ?string, string}
     */
    public static function described(Closure $call): array
    {
        $failure = self::of($call);

        return [$failure->errorCode->value, $failure->path?->toString(), $failure->reason];
    }
}
