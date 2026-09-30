<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Delivery\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Core\Delivery\Domain\AnswerFormat;
use Cbox\Cms\Core\Delivery\Domain\DeliverySource;
use Cbox\Cms\Core\Delivery\Domain\Dto\CacheDirective;
use Cbox\Cms\Core\Delivery\Domain\Dto\Delivery;
use Illuminate\Http\Response;

/**
 * Writes an answer of the delivery API's resolve (PRD 8.8, 8.10, 8.12, 9.4):
 *
 * - the status and the body, `application/json` for a record and an explanation and
 *   `application/problem+json` for a problem;
 * - `Surrogate-Key` with the answer's content keys, space-separated, which the edge strips before
 *   the answer leaves it (point 4);
 * - `Cache-Control`: `public, max-age=0, s-maxage=<seconds>` for an answer a shared cache may
 *   keep, with `stale-while-revalidate` and `stale-if-error` when it may be served stale, and
 *   `private, no-store` for every other answer;
 * - `Cbox-Cache: hit` for an answer served from a fragment and `miss` for one from the origin.
 *
 * It sets no cookie and no `Vary` (point 6).
 */
#[Internal]
final readonly class DeliveryOutput
{
    public const string SURROGATE_KEY = 'Surrogate-Key';

    public const string SOURCE = 'Cbox-Cache';

    public static function response(Delivery $delivery): Response
    {
        $response = new Response($delivery->body, $delivery->status->value, [
            'Content-Type' => $delivery->format === AnswerFormat::Problem ? 'application/problem+json' : 'application/json',
            self::SOURCE => $delivery->source === DeliverySource::Fragment ? 'hit' : 'miss',
        ]);
        $response->headers->set('Cache-Control', self::cacheControl($delivery->cache));

        if ($delivery->contentKeys !== []) {
            $response->headers->set(self::SURROGATE_KEY, implode(' ', array_map(static fn (DependencyKey $key): string => $key->toString(), $delivery->contentKeys)));
        }

        return $response;
    }

    private static function cacheControl(CacheDirective $cache): string
    {
        if (! $cache->shared) {
            return 'no-store, private';
        }

        $directives = ['max-age=0', 'public', 's-maxage='.$cache->maxAge];

        if ($cache->staleWhileRevalidate > 0) {
            $directives[] = 'stale-while-revalidate='.$cache->staleWhileRevalidate;
        }

        if ($cache->staleIfError > 0) {
            $directives[] = 'stale-if-error='.$cache->staleIfError;
        }

        return implode(', ', $directives);
    }
}
