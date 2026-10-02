<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Protocol;

use Cbox\Cms\Contracts\Attributes\Command;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Core\Codecs\Boundary\Generated\KernelCommandCodecs;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCodec;
use Cbox\Cms\Generators\Codec\Domain\Dto\CodecContract;
use Cbox\Cms\Generators\Codec\Domain\Dto\PhpLocation;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Protocol\Boundary\JsonSchemaContract;
use Cbox\Cms\Generators\Protocol\Domain\Dto\SchemaBinding;
use Cbox\Cms\Generators\Protocol\Domain\ProtocolSchemas;
use LogicException;
use ReflectionClass;
use ReflectionMethod;

/*
 * The kernel's JSON Schemas and their codecs (GUARDRAILS 2.2, PRD 6.1, 8.4, 8.8): the committed
 * codecs are exactly what the committed schemas generate, one per contract version, and each codec
 * implements JsonCodec for the class the schema is bound to. The codec of a command carries the
 * command's schema and builds its CommandCodec, and KernelCommandCodecs lists every one.
 */

function kernelRoot(): string
{
    return dirname(__DIR__, 4);
}

/**
 * @return list<GeneratedFile>
 */
function kernelCodecs(): array
{
    $contracts = array_map(
        static fn (SchemaBinding $binding): CodecContract => JsonSchemaContract::read(
            (string) file_get_contents(kernelRoot().'/'.$binding->path()),
            $binding,
            ProtocolSchemas::ATTRIBUTE,
        ),
        ProtocolSchemas::all(),
    );

    return ProtocolSchemas::result($contracts, new PhpLocation(ProtocolSchemas::PHP_DIRECTORY, ProtocolSchemas::PHP_NAMESPACE))->files;
}

it('writes exactly the committed codecs of the receipt, problem details, envelope, delivery, explanation and command schemas, and the list of the commands\' codecs', function (): void {
    $files = kernelCodecs();

    expect(array_map(static fn (GeneratedFile $file): string => $file->path, $files))->toBe([
        'packages/core/src/Codecs/Boundary/Generated/ActivateActorCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/CreateEntryCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/CreatePlacementCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/DeactivateActorCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/DeliveryCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/DeliveryExplanationCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/DeliveryFragmentCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/EnvelopeCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/ExplainedPathCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/KernelCommandCodecs.php',
        'packages/core/src/Codecs/Boundary/Generated/PathExplanationCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/ProblemCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/PublishEntryCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/ReceiptCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/RegisterActorCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/ReleaseVariantCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/ReviseEntryCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/SetPlacementWindowCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/UnpublishEntryCodecV1.php',
    ]);

    foreach ($files as $file) {
        expect(file_get_contents(kernelRoot().'/'.$file->path))->toBe($file->contents, $file->path.' is not what composer generate:protocol writes.');
    }

    expect(glob(kernelRoot().'/'.ProtocolSchemas::PHP_DIRECTORY.'/*'))->toHaveCount(19);
});

it('gives each command\'s codec the command\'s name and version from its #[Command] and its schema, and lists each in KernelCommandCodecs', function (SchemaBinding $binding): void {
    $codecClass = ProtocolSchemas::PHP_NAMESPACE.'\\'.$binding->codecClass;
    $command = $binding->command ?? throw new LogicException($binding->schema.' names no command.');

    if (! class_exists($command)) {
        throw new LogicException($command.' is not a class.');
    }

    $attribute = new ReflectionClass($command)->getAttributes(Command::class)[0]->newInstance();
    $listed = array_values(array_filter(KernelCommandCodecs::all(), static fn (CommandCodec $each): bool => $codecClass === $each->codec::class));
    $commandCodec = $listed[0] ?? throw new LogicException($codecClass.' is not listed in KernelCommandCodecs.');

    expect($binding->directory)->toBe(ProtocolSchemas::COMMAND_SCHEMA_DIRECTORY)
        ->and($binding->objects['#'])->toBe($command)
        ->and($binding->schema)->toBe($attribute->name.'.v'.$attribute->version.'.json')
        ->and($listed)->toHaveCount(1)
        ->and($commandCodec->command->value)->toBe($attribute->name)
        ->and($commandCodec->version)->toBe($attribute->version)
        ->and(json_decode($commandCodec->schema->json, true, 64, JSON_THROW_ON_ERROR))->toBe(json_decode((string) file_get_contents(kernelRoot().'/'.$binding->path()), true, 64, JSON_THROW_ON_ERROR));
})->with(static fn (): array => array_combine(
    array_map(static fn (SchemaBinding $binding): string => $binding->schema, ProtocolSchemas::commands()),
    array_map(static fn (SchemaBinding $binding): array => [$binding], ProtocolSchemas::commands()),
));

it('lists exactly one codec per command binding', function (): void {
    expect(KernelCommandCodecs::all())->toHaveCount(count(ProtocolSchemas::commands()));
});

it('binds each contract version to its file and codec, with the codec implementing JsonCodec for the bound class', function (SchemaBinding $binding): void {
    $codec = ProtocolSchemas::PHP_NAMESPACE.'\\'.$binding->codecClass;

    if (! class_exists($codec)) {
        throw new LogicException($codec.' does not exist.');
    }

    $implements = class_implements($codec);

    expect($binding->schema)->toEndWith('.v'.$binding->version.'.json')
        ->and($binding->codecClass)->toEndWith('CodecV'.$binding->version)
        ->and(is_file(kernelRoot().'/'.$binding->path()))->toBeTrue()
        ->and($implements)->toHaveKey(JsonCodec::class)
        ->and(new ReflectionClass($codec)->getAttributes(Experimental::class))->toHaveCount(1)
        ->and((string) new ReflectionMethod($codec, 'decode')->getReturnType())->toBe($binding->objects['#']);
})->with(static fn (): array => array_combine(
    array_map(static fn (SchemaBinding $binding): string => $binding->schema, ProtocolSchemas::all()),
    array_map(static fn (SchemaBinding $binding): array => [$binding], ProtocolSchemas::all()),
));

it('sorts the codecs by path, owns their directory and refuses two schemas with one codec', function (): void {
    $location = new PhpLocation('Generated', 'Cbox\Cms\Probe\Generated');
    $parcel = ParcelSchema::contract();
    $binding = ParcelSchema::binding();
    $receipt = kernelCodecs();
    $other = JsonSchemaContract::read(ParcelSchema::json(), new SchemaBinding('a.v1.json', 'AParcelCodecV1', 1, $binding->objects, $binding->values), Experimental::class);
    $result = ProtocolSchemas::result([$parcel, $other], $location);

    expect(array_map(static fn (GeneratedFile $file): string => $file->path, $result->files))->toBe(['Generated/AParcelCodecV1.php', 'Generated/ParcelCodecV1.php'])
        ->and($result->directories)->toBe(['Generated'])
        ->and($receipt)->toHaveCount(19);

    try {
        ProtocolSchemas::result([$parcel, $parcel], $location);
    } catch (GenerationFailed $failed) {
        expect($failed->codes())->toBe([GenerateErrorCode::InvalidOutput])
            ->and($failed->problems[0]->message)->toBe('Two kernel schemas have the codec ParcelCodecV1; give each contract version its own.');

        return;
    }

    throw new LogicException('Two schemas with one codec were accepted.');
});
