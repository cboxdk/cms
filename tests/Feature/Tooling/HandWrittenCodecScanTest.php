<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling;

use Cbox\Cms\Contracts\Errors\Problem;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Codecs\Boundary\JsonValues;
use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Arch\Fixtures\Codecs\ArraySlip;
use Cbox\Cms\Tests\Support\Arch\Fixtures\Codecs\ObjectJsonSlip;
use Cbox\Cms\Tests\Support\Arch\Fixtures\Codecs\SelfEncodingSlip;
use Cbox\Cms\Tests\Support\Arch\HandWrittenCodecScan;
use Cbox\Cms\Tests\Support\Arch\SourceFile;

/*
 * The scan behind tests/Arch/GeneratedCodecsTest.php (GUARDRAILS 2.2): code that names a class a
 * kernel schema binds and turns something into JSON or back, outside a Generated directory, a
 * hand-written JsonCodec, and a bound class that serialises itself are reported; the generated
 * codecs and code that only uses a bound class are not.
 */

/**
 * The findings of the scan over one planted file at $path below the repository.
 *
 * @param  list<string>  $codecs  the classes that count as implementations of JsonCodec
 * @return list<string>
 */
function codecFindings(string $path, string $code, array $codecs = []): array
{
    return HandWrittenCodecScan::findings(
        [SourceFile::parse(Codebase::root().'/'.$path, $code)],
        [Problem::class, Receipt::class],
        static fn (string $class): bool => in_array($class, $codecs, true),
    );
}

const HAND_WRITTEN_RECEIPT = <<<'PHP'
    <?php

    declare(strict_types=1);

    namespace Cbox\Cms\Http\Boundary;

    use Cbox\Cms\Contracts\Receipts\Receipt;

    final readonly class ReceiptBody
    {
        public static function of(Receipt $receipt): string
        {
            return json_encode(['outcome' => $receipt->outcome->value]);
        }
    }
    PHP;

it('reports a file that names a bound class and encodes JSON by hand, at the JSON call', function (): void {
    expect(codecFindings('packages/http/src/Boundary/ReceiptBody.php', HAND_WRITTEN_RECEIPT))->toBe([
        'packages/http/src/Boundary/ReceiptBody.php:13: serialises Cbox\Cms\Contracts\Receipts\Receipt by hand with json_encode; its JSON form is written and read only by its generated codec.',
    ]);
});

it('reports every other way to write or read JSON next to a bound class, at its first reference', function (string $use, string $body, string $with, int $line): void {
    $code = <<<PHP
        <?php

        declare(strict_types=1);

        namespace Cbox\\Cms\\Http\\Boundary;

        use Cbox\\Cms\\Contracts\\Errors\\Problem;
        {$use}

        final readonly class ProblemBody
        {
            public static function of(Problem \$problem, mixed \$value): mixed
            {
                {$body}
            }
        }
        PHP;

    expect(codecFindings('packages/http/src/Boundary/ProblemBody.php', $code))->toBe([
        'packages/http/src/Boundary/ProblemBody.php:'.$line.': serialises Cbox\Cms\Contracts\Errors\Problem by hand with '.$with.'; its JSON form is written and read only by its generated codec.',
    ]);
})->with([
    'json_decode' => ['', 'return json_decode($value);', 'json_decode', 14],
    'JsonText, at its import' => ['use Cbox\Cms\Core\Codecs\Boundary\JsonText;', 'return JsonText::decode($value);', JsonText::class, 8],
    'JsonValues, at its import' => ['use Cbox\Cms\Core\Codecs\Boundary\JsonValues;', 'return JsonValues::encodeDatetime($value);', JsonValues::class, 8],
    'jsonSerialize()' => ['', 'return $value->jsonSerialize();', 'jsonserialize', 14],
    'JsonSerializable, at its import' => ['use JsonSerializable;', 'return $value instanceof JsonSerializable;', 'JsonSerializable', 8],
]);

it('leaves the generated codecs, code that only uses a bound class and JSON of other classes alone', function (): void {
    $onlyUses = str_replace("json_encode(['outcome' => \$receipt->outcome->value])", '$receipt->outcome->value', HAND_WRITTEN_RECEIPT);
    $otherJson = str_replace(['use Cbox\Cms\Contracts\Receipts\Receipt;', 'Receipt $receipt', '$receipt->outcome->value'], ['use Cbox\Cms\Contracts\Consistency\Outcome;', 'Outcome $receipt', '$receipt->value'], HAND_WRITTEN_RECEIPT);

    expect(codecFindings('packages/core/src/Codecs/Boundary/Generated/ReceiptCodecV1.php', HAND_WRITTEN_RECEIPT))->toBe([])
        ->and(codecFindings('workbench/app/Cms/Generated/Boundary/ReceiptBody.php', HAND_WRITTEN_RECEIPT))->toBe([])
        ->and(codecFindings('packages/http/src/Boundary/ReceiptBody.php', $onlyUses))->toBe([])
        ->and(codecFindings('packages/http/src/Boundary/ReceiptBody.php', $otherJson))->toBe([]);
});

it('reports a class that implements JsonCodec outside a Generated directory', function (): void {
    $code = str_replace('final readonly class ReceiptBody', 'final readonly class ReceiptCodec', HAND_WRITTEN_RECEIPT);
    $codecs = ['Cbox\Cms\Http\Boundary\ReceiptCodec'];

    expect(codecFindings('packages/http/src/Boundary/ReceiptCodec.php', str_replace('json_encode', 'strval', $code), $codecs))->toBe([
        'packages/http/src/Boundary/ReceiptCodec.php:9: Cbox\Cms\Http\Boundary\ReceiptCodec implements Cbox\Cms\Contracts\Codecs\JsonCodec by hand; a codec is generated into a directory named Generated.',
    ])->and(codecFindings('packages/http/src/Generated/ReceiptCodec.php', $code, $codecs))->toBe([]);
});

it('reports a bound class that serialises itself', function (): void {
    expect(HandWrittenCodecScan::findings([], [SelfEncodingSlip::class, ArraySlip::class, Receipt::class], static fn (string $class): bool => false))->toBe([
        SelfEncodingSlip::class.' serialises itself (JsonSerializable, jsonserialize); its JSON form is written only by its generated codec.',
        ArraySlip::class.' serialises itself (toarray); its JSON form is written only by its generated codec.',
    ]);
});

/**
 * The findings of the scan over the planted JSON helpers and one planted file at $path that uses
 * them.
 *
 * @return list<string>
 */
function helperFindings(string $path, string $code): array
{
    $fixtures = Codebase::root().'/tests/Support/Arch/Fixtures/Codecs';

    return HandWrittenCodecScan::findings(
        [
            SourceFile::read($fixtures.'/ObjectJsonSlip.php'),
            SourceFile::read($fixtures.'/JsonConstantsSlip.php'),
            SourceFile::parse(Codebase::root().'/'.$path, $code),
        ],
        [Problem::class, Receipt::class],
        static fn (string $class): bool => false,
    );
}

const RECEIPT_THROUGH_HELPER = <<<'PHP'
    <?php

    declare(strict_types=1);

    namespace Cbox\Cms\Http\Boundary;

    use Cbox\Cms\Contracts\Receipts\Receipt;
    use Cbox\Cms\Tests\Support\Arch\Fixtures\Codecs\ObjectJsonSlip;
    use stdClass;

    final readonly class ReceiptBody
    {
        public static function of(Receipt $receipt): string
        {
            $json = new stdClass;
            $json->outcome = $receipt->outcome->value;

            return ObjectJsonSlip::encode($json);
        }
    }
    PHP;

it('reports a file that names a bound class and writes it through a JSON helper that takes or gives an object', function (): void {
    expect(helperFindings('packages/http/src/Boundary/ReceiptBody.php', RECEIPT_THROUGH_HELPER))->toBe([
        'packages/http/src/Boundary/ReceiptBody.php:8: serialises Cbox\Cms\Contracts\Receipts\Receipt by hand with '.ObjectJsonSlip::class.'; its JSON form is written and read only by its generated codec.',
    ]);
});

it('reports a file in a JSON helper\'s own namespace, which uses it without an import, at its first bound class', function (): void {
    $code = str_replace(
        ['namespace Cbox\Cms\Http\Boundary;', "use Cbox\\Cms\\Tests\\Support\\Arch\\Fixtures\\Codecs\\ObjectJsonSlip;\n"],
        ['namespace Cbox\Cms\Tests\Support\Arch\Fixtures\Codecs;', ''],
        RECEIPT_THROUGH_HELPER,
    );

    expect(helperFindings('tests/Support/Arch/Fixtures/Codecs/ReceiptBody.php', $code))->toBe([
        'tests/Support/Arch/Fixtures/Codecs/ReceiptBody.php:7: serialises Cbox\Cms\Contracts\Receipts\Receipt by hand with '.ObjectJsonSlip::class.'; its JSON form is written and read only by its generated codec.',
    ]);
});

it('does not count a class that writes JSON but takes and gives no object as a JSON helper', function (): void {
    $code = str_replace(
        ['use Cbox\Cms\Tests\Support\Arch\Fixtures\Codecs\ObjectJsonSlip;', 'return ObjectJsonSlip::encode($json);'],
        ['use Cbox\Cms\Tests\Support\Arch\Fixtures\Codecs\JsonConstantsSlip;', 'return JsonConstantsSlip::MEDIA_TYPE.$json->outcome;'],
        RECEIPT_THROUGH_HELPER,
    );

    expect(helperFindings('packages/http/src/Boundary/ReceiptBody.php', $code))->toBe([]);
});
