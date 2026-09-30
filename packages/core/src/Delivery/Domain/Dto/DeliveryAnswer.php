<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Delivery\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Errors\HttpStatus;
use Cbox\Cms\Contracts\Errors\Problem;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Delivery\Domain\AnswerFormat;
use Cbox\Cms\Core\Routing\Domain\Dto\PathExplanation;
use InvalidArgumentException;

/**
 * What an answer of the delivery API says, before its body is written (PRD 8.8, 8.9): a record, the
 * JSON the type's generated codec wrote at the public classification access, with its type, the
 * locale and the canonical URL; or a problem of the error catalog; and for an authorized actor who
 * asked for it, the explanation of the resolution next to either.
 */
#[Internal]
final readonly class DeliveryAnswer
{
    /**
     * @throws InvalidArgumentException unless the answer holds a record with its type and locale, or a problem
     */
    public function __construct(
        public HttpStatus $status,
        public ?string $record = null,
        public ?TypeName $type = null,
        public ?Locale $locale = null,
        public ?string $canonicalUrl = null,
        public ?Problem $problem = null,
        public ?PathExplanation $explanation = null,
    ) {
        $record = $record !== null && $type instanceof TypeName && $locale instanceof Locale;

        if ($record === $problem instanceof Problem) {
            throw new InvalidArgumentException('An answer of the delivery API holds a record with its type and locale, or a problem, and not both.');
        }
    }

    public static function problem(Problem $problem, ?PathExplanation $explanation = null): self
    {
        return new self(HttpStatus::from($problem->status), problem: $problem, explanation: $explanation);
    }

    public function format(): AnswerFormat
    {
        return match (true) {
            $this->explanation instanceof PathExplanation => AnswerFormat::Explanation,
            $this->problem instanceof Problem => AnswerFormat::Problem,
            default => AnswerFormat::Record,
        };
    }
}
