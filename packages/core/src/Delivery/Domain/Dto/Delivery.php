<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Delivery\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Errors\HttpStatus;
use Cbox\Cms\Core\Delivery\Domain\AnswerFormat;
use Cbox\Cms\Core\Delivery\Domain\DeliverySource;

/**
 * An answer of the delivery API's resolve, ready for the surface to send (PRD 8.9, 8.10): its
 * status, the form and the bytes of its body, the content keys a cache of it depends on, each once
 * and sorted (PRD 9.4), how long a shared cache may keep it, and whether it came from a fragment.
 */
#[Internal]
final readonly class Delivery
{
    /** @var list<DependencyKey> each once, sorted */
    public array $contentKeys;

    /**
     * @param  list<DependencyKey>  $contentKeys
     */
    public function __construct(
        public HttpStatus $status,
        public AnswerFormat $format,
        public string $body,
        array $contentKeys,
        public CacheDirective $cache,
        public DeliverySource $source,
    ) {
        $byKey = [];

        foreach ($contentKeys as $key) {
            $byKey[$key->toString()] = $key;
        }

        ksort($byKey, SORT_STRING);
        $this->contentKeys = array_values($byKey);
    }
}
