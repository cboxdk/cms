<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Delivery\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\HttpStatus;
use Cbox\Cms\Core\Delivery\Domain\AnswerFormat;

/**
 * What a fragment of the delivery API holds (PRD 8.12, 9.3): the status, the form and the bytes of
 * the answer's body, and whether a shared cache may serve it stale. Its content keys and its
 * valid_until are the fragment's own.
 */
#[Internal]
final readonly class StoredAnswer
{
    public function __construct(
        public HttpStatus $status,
        public AnswerFormat $format,
        public string $body,
        public bool $stale,
    ) {}
}
