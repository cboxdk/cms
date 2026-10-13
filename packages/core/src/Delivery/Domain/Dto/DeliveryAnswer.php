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
use Cbox\Cms\Core\Routing\Domain\Dto\RedirectStep;
use InvalidArgumentException;

/**
 * What an answer of the delivery API says, before its body is written (PRD 8.8, 8.9): a record, the
 * JSON the type's generated codec wrote at the public classification access, with its type, the
 * locale and the canonical URL; a problem of the error catalog; or a redirect (PRD 5.8), the step
 * of the explanation with the URL the page lives at, which is sent in Location with no body; and
 * for an authorized actor who asked for it, the explanation of the resolution next to any of them.
 */
#[Internal]
final readonly class DeliveryAnswer
{
    /**
     * @throws InvalidArgumentException unless the answer holds exactly one of a record with its type and locale, a problem, and a redirect with the status 301
     */
    public function __construct(
        public HttpStatus $status,
        public ?string $record = null,
        public ?TypeName $type = null,
        public ?Locale $locale = null,
        public ?string $canonicalUrl = null,
        public ?Problem $problem = null,
        public ?PathExplanation $explanation = null,
        public ?RedirectStep $redirect = null,
    ) {
        $record = $record !== null && $type instanceof TypeName && $locale instanceof Locale;
        $held = count(array_filter([$record, $problem instanceof Problem, $redirect instanceof RedirectStep]));

        if ($held !== 1) {
            throw new InvalidArgumentException('An answer of the delivery API holds a record with its type and locale, a problem, or a redirect, and exactly one of them.');
        }

        if ($redirect instanceof RedirectStep && $status !== HttpStatus::MovedPermanently) {
            throw new InvalidArgumentException(sprintf('A redirect of the delivery API is answered with %d, got %d.', HttpStatus::MovedPermanently->value, $status->value));
        }
    }

    public static function redirect(RedirectStep $redirect, ?PathExplanation $explanation = null): self
    {
        return new self(HttpStatus::MovedPermanently, explanation: $explanation, redirect: $redirect);
    }

    public static function problem(Problem $problem, ?PathExplanation $explanation = null): self
    {
        return new self(HttpStatus::from($problem->status), problem: $problem, explanation: $explanation);
    }

    /**
     * The URL a redirect sends to, its Location; null for every other answer, the explanation of
     * a redirect included, which names the URL in its redirect step.
     */
    public function location(): ?string
    {
        return $this->format() === AnswerFormat::Redirect ? $this->redirect?->url : null;
    }

    public function format(): AnswerFormat
    {
        return match (true) {
            $this->explanation instanceof PathExplanation => AnswerFormat::Explanation,
            $this->problem instanceof Problem => AnswerFormat::Problem,
            $this->redirect instanceof RedirectStep => AnswerFormat::Redirect,
            default => AnswerFormat::Record,
        };
    }
}
