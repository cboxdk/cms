<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Codecs;

use Cbox\Cms\Contracts\Codecs\InvalidJsonDocument;
use Cbox\Cms\Contracts\Codecs\InvalidRecordDocument;
use Cbox\Cms\Contracts\Ids\TypeId;
use JsonException;

/*
 * A document that is not JSON is refused with the parser's reason after the kernel's, the parser's
 * exception kept as the cause and the exception code 0, and so is a record that cannot be written.
 */

it('says why a document is not well-formed, after the kernel\'s words, and keeps the cause', function (): void {
    $cause = new JsonException('Syntax error', 4);
    $refused = InvalidJsonDocument::malformed($cause);

    expect($refused->getMessage())->toBe('A JSON document is not well-formed JSON, or nests too deep: Syntax error')
        ->and($refused->getCode())->toBe(0)
        ->and($refused->getPrevious())->toBe($cause)
        ->and(InvalidJsonDocument::notAnObject()->getMessage())->toBe('A JSON document is one JSON object, not another JSON value.');
});

it('says why a record cannot be written, keeps the cause and has the exception code 0', function (): void {
    $cause = new JsonException('Malformed UTF-8 characters', 5);
    $refused = InvalidRecordDocument::refused(TypeId::fromString('0198d2a4-5c3e-7a41-9b2f-000000000301'), 'the value is not UTF-8', $cause);

    expect([$refused->getMessage(), $refused->getCode(), $refused->getPrevious()])
        ->toBe(['The record of the type 0198d2a4-5c3e-7a41-9b2f-000000000301 cannot be written: the value is not UTF-8', 0, $cause]);
});
