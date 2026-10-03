<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Pipeline\Result;
use Cbox\Cms\Panel\Contributions\Domain\DataOutcome;
use Cbox\Cms\Panel\Contributions\Domain\DataRefusal;
use Throwable;

/**
 * What a contribution's data query gave (PRD 13.4): for an answered query its result and the
 * classification access the read had, at most the addon's reads, which the query's result codec
 * writes it at; otherwise nothing, with the catalog code of a rejection, the reason of a refusal or
 * the exception of a failure, which the surface reports.
 */
#[Experimental]
final readonly class ContributionData
{
    private function __construct(
        public DataOutcome $outcome,
        public ?Result $result = null,
        public ClassificationAccess $access = ClassificationAccess::Public,
        public ?ErrorCode $code = null,
        public ?DataRefusal $refusal = null,
        public ?Throwable $failure = null,
    ) {}

    public static function answered(Result $result, ClassificationAccess $access): self
    {
        return new self(DataOutcome::Answered, $result, $access);
    }

    public static function rejected(ErrorCode $code): self
    {
        return new self(DataOutcome::Rejected, code: $code);
    }

    public static function refused(DataRefusal $refusal): self
    {
        return new self(DataOutcome::Refused, refusal: $refusal);
    }

    public static function failed(Throwable $failure): self
    {
        return new self(DataOutcome::Failed, failure: $failure);
    }
}
