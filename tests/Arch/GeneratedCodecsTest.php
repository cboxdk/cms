<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Arch;

use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Contracts\Envelope\RequestEnvelope;
use Cbox\Cms\Contracts\Errors\Problem;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Generators\Protocol\Domain\Dto\SchemaBinding;
use Cbox\Cms\Generators\Protocol\Domain\ProtocolSchemas;
use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Arch\HandWrittenCodecScan;
use Cbox\Cms\Tests\Support\Arch\Rules;

/*
 * Serialisation is generated (GUARDRAILS 2.2): the JSON form of the receipt, the problem details
 * document and the envelope is fixed by their JSON Schemas and written and read only by the codecs
 * composer generate:protocol writes into a Generated directory. No code in packages/*\/src or
 * workbench/app serialises a class those schemas bind by hand, no class outside a Generated
 * directory implements JsonCodec, and no bound class serialises itself.
 * tests/Feature/Tooling/HandWrittenCodecScanTest.php covers what the scan finds.
 */

arch('generated codecs: no hand-written encoder of a class the kernel schemas bind, and no hand-written codec', function (): void {
    $bound = array_values(array_unique(array_merge(...array_map(
        static fn (SchemaBinding $binding): array => array_values($binding->objects),
        ProtocolSchemas::kernel(),
    ))));
    sort($bound);

    expect($bound)->toContain(Receipt::class, Problem::class, RequestEnvelope::class);

    Rules::none(
        HandWrittenCodecScan::findings(
            Codebase::code(),
            $bound,
            static fn (string $class): bool => class_exists($class) && in_array(JsonCodec::class, class_implements($class), true),
        ),
        'The JSON form of the classes the kernel schemas bind is written only by their generated codecs, and every codec is generated (GUARDRAILS 2.2):',
    );
});
