<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Codecs;

use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;

/*
 * Each codec composer generate:protocol writes into packages/core/src/Codecs/Boundary/Generated
 * declares the contract version it reads and writes in VERSION, and its name ends in that version
 * (GUARDRAILS 2.2): ReceiptCodecV1 is version 1. A codec whose VERSION said otherwise would answer
 * for another version of its contract than its name and its schema file.
 */

/**
 * Each generated codec's class and the version its name ends in.
 *
 * @return array<string, array{class-string, int}>
 */
function generatedCodecs(): array
{
    $codecs = [];

    foreach (glob(dirname(__DIR__, 2).'/src/Codecs/Boundary/Generated/*CodecV*.php') ?: [] as $file) {
        $class = 'Cbox\\Cms\\Core\\Codecs\\Boundary\\Generated\\'.basename($file, '.php');

        if (preg_match('/CodecV([1-9][0-9]*)$/', $class, $version) === 1 && class_exists($class)) {
            $codecs[basename($file, '.php')] = [$class, (int) $version[1]];
        }
    }

    ksort($codecs);

    return $codecs;
}

it('finds the generated codecs', function (): void {
    expect(array_keys(generatedCodecs()))->toContain('EnvelopeCodecV1', 'ExplainedPathCodecV1', 'ProblemCodecV1', 'ReceiptCodecV1', 'CreateEntryCodecV1');
});

it('declares in VERSION the contract version its name ends in, and refuses a document that is no object', function (string $class, int $version): void {
    $codec = new $class;
    $refused = null;

    try {
        if ($codec instanceof JsonCodec) {
            $codec->decode('[]', ClassificationAccess::Sensitive);
        }
    } catch (DecodingFailed $failed) {
        $refused = $failed;
    }

    expect(constant($class.'::VERSION'))->toBe($version)
        ->and($codec)->toBeInstanceOf(JsonCodec::class)
        ->and($refused)->toBeInstanceOf(DecodingFailed::class);
})->with(generatedCodecs());
