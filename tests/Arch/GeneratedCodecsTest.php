<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Arch;

use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Contracts\Codecs\JsonDocument;
use Cbox\Cms\Contracts\Envelope\RequestEnvelope;
use Cbox\Cms\Contracts\Errors\Problem;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Core\Delivery\Domain\Dto\DeliveryDocument;
use Cbox\Cms\Core\Delivery\Domain\Dto\DeliveryExplanation;
use Cbox\Cms\Core\Delivery\Domain\Dto\StoredAnswer;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry;
use Cbox\Cms\Core\Entries\Domain\Commands\ReviseEntry;
use Cbox\Cms\Core\Routing\Domain\Dto\ExplainedPath;
use Cbox\Cms\Core\Routing\Domain\Dto\PathExplanation;
use Cbox\Cms\Generators\Protocol\Domain\Dto\SchemaBinding;
use Cbox\Cms\Generators\Protocol\Domain\ProtocolSchemas;
use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Arch\DeclaredType;
use Cbox\Cms\Tests\Support\Arch\HandWrittenCodecScan;
use Cbox\Cms\Tests\Support\Arch\Layer;
use Cbox\Cms\Tests\Support\Arch\ReferenceKind;
use Cbox\Cms\Tests\Support\Arch\Rules;
use Cbox\Cms\Tests\Support\Arch\SourceFile;

/*
 * Serialisation is generated (GUARDRAILS 2.2): the JSON form of the receipt, the problem details
 * document, the envelope, the delivery API's documents and fragment, the path explanation and
 * cms:explain's document, and each of the kernel's commands is fixed by their JSON Schemas and
 * written and read only by the codecs composer generate:protocol writes into a Generated directory.
 * No code in packages/*\/src or workbench/app serialises a class the contracts' schemas bind, or a
 * command a command's schema is bound to, by hand, no class outside a Generated directory
 * implements JsonCodec, and no bound class serialises itself. A JSON helper, a class that writes
 * or reads JSON and takes or gives an object, counts as JSON where a file names a bound class. The values a command holds, such as a
 * TimeWindow, appear in other documents too, so only the command itself counts as bound here.
 * tests/Feature/Tooling/HandWrittenCodecScanTest.php covers what the scan finds.
 */

arch('generated codecs: no hand-written encoder of a class the kernel schemas bind, and no hand-written codec', function (): void {
    $bound = array_values(array_unique([
        ...array_merge(...array_map(
            static fn (SchemaBinding $binding): array => array_values($binding->objects),
            ProtocolSchemas::kernel(),
        )),
        ...array_map(static fn (SchemaBinding $binding): string => $binding->objects['#'], ProtocolSchemas::commands()),
    ]));
    sort($bound);

    expect($bound)->toContain(Receipt::class, Problem::class, RequestEnvelope::class, CreateEntry::class, ReviseEntry::class, DeliveryDocument::class, DeliveryExplanation::class, StoredAnswer::class, PathExplanation::class, ExplainedPath::class)
        ->and(ProtocolSchemas::commands())->toHaveCount(10);

    Rules::none(
        HandWrittenCodecScan::findings(
            Codebase::code(),
            $bound,
            static fn (string $class): bool => class_exists($class) && in_array(JsonCodec::class, class_implements($class), true),
        ),
        'The JSON form of the classes the kernel schemas bind is written only by their generated codecs, and every codec is generated (GUARDRAILS 2.2):',
    );
});

/*
 * JSON is written and read only in Boundary and Adapter (GUARDRAILS 2.2, 2.5): a document is an
 * untyped array until a Boundary maps it to DTOs, and its form belongs to the one class that owns
 * it, generated or, for a file format such as the schema lock, hand-written there next to its
 * reader. Code in any other layer gets the text from a Boundary, never by calling json_encode() or
 * json_decode() itself.
 *
 * JSON_LAYER_ALLOWANCES lists the classes outside those layers that may call one, each with its
 * reason; an allowance no code uses fails the rule's second test.
 */

/**
 * @var array<class-string, list<string>>
 */
const JSON_LAYER_ALLOWANCES = [
    // A value object of the contracts module, which has no Boundary (it depends only on PHP), that
    // decodes its text only to check its invariant, one well-formed JSON object; it reads no value
    // and writes no JSON. The generated codecs embed and read it.
    JsonDocument::class => ['json_decode'],
];

/**
 * @return list<string>
 */
function jsonLayerUses(SourceFile $file): array
{
    return array_values(array_unique(array_merge([], ...array_map(
        static fn (DeclaredType $type): array => JSON_LAYER_ALLOWANCES[$type->fqcn()] ?? [],
        $file->types,
    ))));
}

arch('serialisation: no layer but Boundary and Adapter encodes or decodes JSON', function (): void {
    $violations = [];

    foreach (Codebase::code() as $file) {
        foreach ($file->references as $reference) {
            $layer = Layer::of($reference->namespace);

            if ($reference->kind === ReferenceKind::Function
                && in_array($reference->name, ['json_encode', 'json_decode'], true)
                && $layer instanceof Layer
                && ! in_array($layer, [Layer::Boundary, Layer::Adapter], true)
                && ! in_array($reference->name, jsonLayerUses($file), true)) {
                $violations[] = sprintf('%s: %s() in the %s layer', $reference->location(), $reference->name, $layer->value);
            }
        }
    }

    Rules::none($violations, 'Only Boundary and Adapter encode or decode JSON (GUARDRAILS 2.2):');
});

arch('serialisation: every allowance to encode or decode JSON outside Boundary and Adapter is used', function (): void {
    $used = [];

    foreach (Codebase::code() as $file) {
        foreach ($file->types as $type) {
            foreach ($file->references as $reference) {
                if ($reference->kind === ReferenceKind::Function && in_array($reference->name, JSON_LAYER_ALLOWANCES[$type->fqcn()] ?? [], true)) {
                    $used[] = $type->fqcn().' '.$reference->name;
                }
            }
        }
    }

    $unused = [];

    foreach (JSON_LAYER_ALLOWANCES as $class => $names) {
        foreach ($names as $name) {
            if (! in_array($class.' '.$name, $used, true)) {
                $unused[] = $class.' '.$name;
            }
        }
    }

    Rules::none($unused, 'An allowance to encode or decode JSON outside Boundary and Adapter that no code uses:');
});
