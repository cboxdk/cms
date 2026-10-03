<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Protocol;

use Cbox\Cms\Contracts\Attributes\Command;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Query;
use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Core\Codecs\Boundary\Generated\KernelCommandCodecs;
use Cbox\Cms\Core\Codecs\Boundary\Generated\KernelQueryCodecs;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCodec;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCodec;
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
 * command's schema and builds its CommandCodec, and KernelCommandCodecs lists every one; the codec
 * of a query carries the query's schema and builds its QueryCodec with the codec of its result,
 * which carries the result's schema, and KernelQueryCodecs lists every one.
 */

function kernelRoot(): string
{
    return dirname(__DIR__, 4);
}

/**
 * The bindings of the kernel's queries' documents, without their results'.
 *
 * @return list<SchemaBinding>
 */
function kernelQueryBindings(): array
{
    return array_values(array_filter(ProtocolSchemas::queries(), static fn (SchemaBinding $binding): bool => $binding->query !== null));
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

it('writes exactly the committed codecs of the receipt, problem details, envelope, delivery, explanation, command and query schemas, and the lists of the commands\' and the queries\' codecs', function (): void {
    $files = kernelCodecs();

    expect(array_map(static fn (GeneratedFile $file): string => $file->path, $files))->toBe([
        'packages/core/src/Codecs/Boundary/Generated/ActivateActorCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/ActorListCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/AssignGrantCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/CreateEntryCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/CreatePlacementCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/CreateRoleCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/DeactivateActorCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/DeliveryCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/DeliveryExplanationCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/DeliveryFragmentCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/EnvelopeCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/ExplainedPathCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/GrantBootstrapRoleCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/GrantListCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/KernelCommandCodecs.php',
        'packages/core/src/Codecs/Boundary/Generated/KernelQueryCodecs.php',
        'packages/core/src/Codecs/Boundary/Generated/ListActorsCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/ListGrantsCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/ListNodesCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/ListRolesCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/NodeListCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/PathExplanationCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/ProblemCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/PublishEntryCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/ReceiptCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/RegisterActorCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/RegisterSiteCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/ReleaseVariantCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/ResolvePathCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/ResolvedPathCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/ReviseEntryCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/RevokeGrantCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/RoleListCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/SetPlacementWindowCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/SetRolePermissionsCodecV1.php',
        'packages/core/src/Codecs/Boundary/Generated/UnpublishEntryCodecV1.php',
    ]);

    foreach ($files as $file) {
        expect(file_get_contents(kernelRoot().'/'.$file->path))->toBe($file->contents, $file->path.' is not what composer generate:protocol writes.');
    }

    expect(glob(kernelRoot().'/'.ProtocolSchemas::PHP_DIRECTORY.'/*'))->toHaveCount(36);
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

it('gives each query\'s codec the query\'s name and version from its #[Query], its schema and its result\'s codec and schema, and lists each in KernelQueryCodecs', function (SchemaBinding $binding): void {
    $codecClass = ProtocolSchemas::PHP_NAMESPACE.'\\'.$binding->codecClass;
    $query = $binding->query ?? throw new LogicException($binding->schema.' names no query.');

    if (! class_exists($query)) {
        throw new LogicException($query.' is not a class.');
    }

    $attribute = new ReflectionClass($query)->getAttributes(Query::class)[0]->newInstance();
    $result = array_first(array_filter(ProtocolSchemas::queries(), static fn (SchemaBinding $each): bool => $each->resultOf === $query && $each->version === $binding->version))
        ?? throw new LogicException($query.' has no result schema.');
    $listed = array_values(array_filter(KernelQueryCodecs::all(), static fn (QueryCodec $each): bool => $codecClass === $each->query::class));
    $queryCodec = $listed[0] ?? throw new LogicException($codecClass.' is not listed in KernelQueryCodecs.');

    expect($binding->directory)->toBe(ProtocolSchemas::QUERY_SCHEMA_DIRECTORY)
        ->and($binding->objects['#'])->toBe($query)
        ->and($binding->schema)->toBe($attribute->name.'.v'.$attribute->version.'.json')
        ->and($binding->resultCodec)->toBe($result->codecClass)
        ->and($result->directory)->toBe(ProtocolSchemas::QUERY_SCHEMA_DIRECTORY)
        ->and($result->schema)->toBe($attribute->name.'.result.v'.$attribute->version.'.json')
        ->and($listed)->toHaveCount(1)
        ->and($queryCodec->name->value)->toBe($attribute->name)
        ->and($queryCodec->version)->toBe($attribute->version)
        ->and($queryCodec->result::class)->toBe(ProtocolSchemas::PHP_NAMESPACE.'\\'.$result->codecClass)
        ->and(json_decode($queryCodec->querySchema->json, true, 64, JSON_THROW_ON_ERROR))->toBe(json_decode((string) file_get_contents(kernelRoot().'/'.$binding->path()), true, 64, JSON_THROW_ON_ERROR))
        ->and(json_decode($queryCodec->resultSchema->json, true, 64, JSON_THROW_ON_ERROR))->toBe(json_decode((string) file_get_contents(kernelRoot().'/'.$result->path()), true, 64, JSON_THROW_ON_ERROR));
})->with(static fn (): array => array_combine(
    array_map(static fn (SchemaBinding $binding): string => $binding->schema, kernelQueryBindings()),
    array_map(static fn (SchemaBinding $binding): array => [$binding], kernelQueryBindings()),
));

it('lists exactly one codec per query binding, and a result binding for each', function (): void {
    expect(KernelQueryCodecs::all())->toHaveCount(count(kernelQueryBindings()))
        ->and(ProtocolSchemas::queries())->toHaveCount(2 * count(kernelQueryBindings()));
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
        ->and($receipt)->toHaveCount(36);

    try {
        ProtocolSchemas::result([$parcel, $parcel], $location);
    } catch (GenerationFailed $failed) {
        expect($failed->codes())->toBe([GenerateErrorCode::InvalidOutput])
            ->and($failed->problems[0]->message)->toBe('Two kernel schemas have the codec ParcelCodecV1; give each contract version its own.');

        return;
    }

    throw new LogicException('Two schemas with one codec were accepted.');
});
