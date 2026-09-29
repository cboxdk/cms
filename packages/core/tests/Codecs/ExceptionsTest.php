<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Codecs;

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Codecs\Domain\EncodingFailed;
use LogicException;
use UnexpectedValueException;

/*
 * The failures of the generated codecs (GUARDRAILS 2.2, 7.2): a refused document carries its code
 * from the error catalog, the path of the value and the reason, and the message says all three.
 */

it('says the code, the path and the reason of a refused document', function (): void {
    $cause = new LogicException('cause');
    $invalid = DecodingFailed::invalid(new FieldPath('sources', 1, 'url'), 'is not in the format url', $cause);
    $malformed = DecodingFailed::malformed('an object has the key "a" twice');

    expect($invalid)->toBeInstanceOf(UnexpectedValueException::class)
        ->and($invalid->getMessage())->toBe('[json_invalid] sources[1].url: is not in the format url.')
        ->and($invalid->errorCode)->toBe(ErrorCode::JsonInvalid)
        ->and($invalid->path?->toString())->toBe('sources[1].url')
        ->and($invalid->reason)->toBe('is not in the format url')
        ->and($invalid->getCode())->toBe(0)
        ->and($invalid->getPrevious())->toBe($cause)
        ->and($malformed->getMessage())->toBe('[json_malformed] an object has the key "a" twice.')
        ->and($malformed->errorCode)->toBe(ErrorCode::JsonMalformed)
        ->and($malformed->path)->toBeNull()
        ->and($malformed->getCode())->toBe(0)
        ->and($malformed->getPrevious())->toBeNull()
        ->and(DecodingFailed::invalid(null, 'has the key "x", which is not a field of the contract')->getMessage())
        ->toBe('[json_invalid] has the key "x", which is not a field of the contract.')
        ->and(ErrorCode::from(DecodingFailed::CODE_INVALID))->toBe(ErrorCode::JsonInvalid)
        ->and(ErrorCode::from(DecodingFailed::CODE_MALFORMED))->toBe(ErrorCode::JsonMalformed);
});

it('says why a DTO cannot be encoded', function (): void {
    $cause = new LogicException('cause');
    $failure = EncodingFailed::because('"x" is not a decimal number', $cause);

    expect($failure)->toBeInstanceOf(LogicException::class)
        ->and($failure->getMessage())->toBe('A generated codec cannot encode the DTO: "x" is not a decimal number.')
        ->and($failure->getCode())->toBe(0)
        ->and($failure->getPrevious())->toBe($cause);
});
