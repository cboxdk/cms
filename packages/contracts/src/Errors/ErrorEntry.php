<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Errors;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * One entry of the error catalog (PRD 6.1, GUARDRAILS 2.1, 7.2): what each surface answers when a
 * call ends with the code, whether trying the same call again makes sense, and an explanation in
 * plain language that says what to do. The concrete cause of one failure is not part of the entry;
 * the error that carries the code gives it.
 *
 * The documentation of every code is a section of the generated error reference, DOCS_PAGE, with
 * the code as its heading; docs() gives its path and anchor.
 */
#[Experimental]
final readonly class ErrorEntry
{
    /** The error reference below the root of cboxdk/cms, generated from the catalog by composer docs:errors. */
    public const string DOCS_PAGE = 'docs/reference/errors.md';

    public function __construct(
        public ErrorCode $code,
        public HttpStatus $http,
        public ExitCode $exit,
        public McpResponse $mcp,
        public bool $retryable,
        public string $explanation,
    ) {
        if (trim($explanation) === '' || ! str_ends_with($explanation, '.')) {
            throw new InvalidArgumentException(sprintf('The explanation of %s must be one or more sentences, ending with a full stop.', $code->value));
        }
    }

    /**
     * The section of the error reference for the code: DOCS_PAGE, # and the code.
     */
    public function docs(): string
    {
        return self::DOCS_PAGE.'#'.$this->code->value;
    }
}
