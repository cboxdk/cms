<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Errors;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Results\CatalogError;

/**
 * A problem details document (RFC 9457, PRD 8.8): how a surface that speaks HTTP answers a call
 * that ends with a code of the error catalog (PRD 6.1).
 *
 * The members of RFC 9457 come from the code's entry: `type` is the section of the error reference
 * for the code (ErrorEntry::docs()), `title` the entry's explanation and `status` its HTTP status.
 * `detail` is the concrete cause of this occurrence and `instance`, when the surface has one, a URI
 * reference that names it. Two members extend RFC 9457: `code`, the catalog code, and `retryable`,
 * whether the same call may succeed later. `errors` lists the reasons a write was rejected, each a
 * CatalogError with the path of the input it is about, so a client can show each at its field.
 *
 * The type, the status and whether the problem is retryable must be what the catalog says for the
 * code; the constructor refuses a document that says otherwise. Its JSON form is problem.v1.json,
 * written and read only by the generated codec,
 * Cbox\Cms\Core\Codecs\Boundary\Generated\ProblemCodecV1 (GUARDRAILS 2.2).
 */
#[Experimental]
final readonly class Problem
{
    /**
     * @param  list<CatalogError>  $errors  in the order the surface reports them
     *
     * @throws InvalidProblem when the document does not answer its code as the catalog says
     */
    public function __construct(
        public string $type,
        public string $title,
        public int $status,
        public string $detail,
        public ?string $instance,
        public ErrorCode $code,
        public bool $retryable,
        public array $errors = [],
    ) {
        $entry = $code->entry();

        if ($type !== $entry->docs()) {
            throw InvalidProblem::type($code, $type);
        }

        if ($status !== $entry->http->value) {
            throw InvalidProblem::status($code, $status);
        }

        if ($retryable !== $entry->retryable) {
            throw InvalidProblem::retryable($code);
        }

        foreach (['title' => $title, 'detail' => $detail, 'instance' => $instance ?? 'none'] as $member => $text) {
            if (trim($text) === '') {
                throw InvalidProblem::emptyText($code, $member);
            }
        }
    }

    /**
     * The problem a surface answers for a code: the catalog's type, title, status and retryable,
     * with the cause of this occurrence, the reasons of a rejected write and the instance.
     *
     * @param  list<CatalogError>  $errors
     */
    public static function of(ErrorCode $code, string $detail, array $errors = [], ?string $instance = null): self
    {
        $entry = $code->entry();

        return new self(
            type: $entry->docs(),
            title: $entry->explanation,
            status: $entry->http->value,
            detail: $detail,
            instance: $instance,
            code: $code,
            retryable: $entry->retryable,
            errors: $errors,
        );
    }
}
