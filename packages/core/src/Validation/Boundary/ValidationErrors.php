<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Validation\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;

/**
 * The field errors one validation collects, at most InputValidator::MAX_ERRORS of them, so input
 * with an error in every item of a long list costs a bounded answer. Once it is full, the validator
 * stops walking the input.
 */
#[Internal]
final class ValidationErrors
{
    /** @var list<CatalogError> */
    private array $errors = [];

    public function __construct(private readonly int $limit) {}

    public function add(ErrorCode $code, ?FieldPath $path, string $message): void
    {
        if (! $this->full()) {
            $this->errors[] = new CatalogError($code, $path, $message);
        }
    }

    public function full(): bool
    {
        return count($this->errors) >= $this->limit;
    }

    /**
     * @return list<CatalogError>
     */
    public function all(): array
    {
        return $this->errors;
    }
}
