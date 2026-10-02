<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Codecs\Queries;

use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Core\Access\Domain\Dto\GrantList;
use Cbox\Cms\Core\Access\Domain\Dto\ListedGrant;
use Cbox\Cms\Core\Access\Domain\Dto\RoleList;
use Cbox\Cms\Core\Access\Domain\Queries\ListGrants;
use Cbox\Cms\Core\Access\Domain\Queries\ListRoles;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ActorListCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\GrantListCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\KernelQueryCodecs;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ListActorsCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ListGrantsCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ListNodesCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ListRolesCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\NodeListCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ResolvedPathCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ResolvePathCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\RoleListCodecV1;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Identity\Domain\Dto\ActorList;
use Cbox\Cms\Core\Identity\Domain\Dto\ListedActor;
use Cbox\Cms\Core\Identity\Domain\Queries\ListActors;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCodec;
use Cbox\Cms\Core\Routing\Domain\Host;
use Cbox\Cms\Core\Routing\Domain\Queries\ResolvePath;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Structure\Domain\Dto\NodeList;
use Cbox\Cms\Core\Structure\Domain\Queries\ListNodes;
use Cbox\Cms\Core\Tests\Access\ListingWorld;
use Cbox\Cms\Core\Tests\Routing\ResolveWorld;
use Cbox\Cms\Generators\Protocol\Domain\Dto\SchemaBinding;
use Cbox\Cms\Generators\Protocol\Domain\ProtocolSchemas;
use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\TypeScript\TypeScriptValidators;
use Closure;
use LogicException;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Formats\DateTimeFormats;
use Opis\JsonSchema\Validator;
use RuntimeException;

/*
 * The generated codecs of the kernel's queries (GUARDRAILS 2.2, PRD 6.2, 8.8), each the codec of a
 * query's document and the codec of its result, as KernelQueryCodecs lists them. Every query and
 * result built in PHP gives an equal one after its codec encodes and decodes it, and what the codec
 * writes is valid against the committed JSON Schema, by opis. On hand-written documents of a query,
 * the PHP codec, the generated TypeScript validator and opis agree. The results of the access
 * queries are also written for a reader below personal access, without their profiles' values
 * (PRD 12.2). Every codec KernelQueryCodecs
 * lists has fixtures here, so a new kernel query fails this test until it is given some.
 */

const QUERY_TYPESCRIPT = 'workbench/resources/js/cms/generated';

/** The end of a date-time of RFC 3339: its offset. */
const QUERY_OFFSET = '/(?:Z|[+-][0-9]{2}:[0-9]{2})\z/i';

/**
 * The round trips of each kernel query codec, by the query's name and version: each checks that
 * the QueryCodec holds the query's and the result's generated codecs and round-trips the queries
 * and results of its fixtures through them.
 *
 * @return array<string, Closure(QueryCodec): void>
 */
function queryFixtures(): array
{
    return [
        'actor.list v1' => static function (QueryCodec $codec): void {
            expect($codec->query)->toBeInstanceOf(ListActorsCodecV1::class)
                ->and($codec->result)->toBeInstanceOf(ActorListCodecV1::class);

            foreach ([new ListActors, new ListActors(ActorId::fromString(ListingWorld::ADMIN), 1)] as $index => $query) {
                queryRoundTrip(new ListActorsCodecV1, $query, 'actor.list.v1.json', 'query '.$index);
            }

            $actors = array_map(static fn (array $actor): ListedActor => $actor[0], ListingWorld::actors());

            foreach ([new ActorList($actors, null), new ActorList([], null), new ActorList([$actors[0]], ActorId::fromString(ListingWorld::ADMIN))] as $index => $result) {
                queryRoundTrip(new ActorListCodecV1, $result, 'actor.list.result.v1.json', 'result '.$index);
                queryWithheld(new ActorListCodecV1, $result, 'actor.list.result.v1.json', 'result '.$index);
            }
        },
        'grant.list v1' => static function (QueryCodec $codec): void {
            expect($codec->query)->toBeInstanceOf(ListGrantsCodecV1::class)
                ->and($codec->result)->toBeInstanceOf(GrantListCodecV1::class);

            foreach ([new ListGrants, new ListGrants(GrantId::fromString(ListingWorld::GRANT_BOB), 100)] as $index => $query) {
                queryRoundTrip(new ListGrantsCodecV1, $query, 'grant.list.v1.json', 'query '.$index);
            }

            $grants = array_map(static fn (array $grant): ListedGrant => $grant[0], ListingWorld::grants());

            foreach ([new GrantList($grants, GrantId::fromString(ListingWorld::GRANT_ENDED)), new GrantList([], null)] as $index => $result) {
                queryRoundTrip(new GrantListCodecV1, $result, 'grant.list.result.v1.json', 'result '.$index);
                queryWithheld(new GrantListCodecV1, $result, 'grant.list.result.v1.json', 'result '.$index);
            }
        },
        'node.list v1' => static function (QueryCodec $codec): void {
            expect($codec->query)->toBeInstanceOf(ListNodesCodecV1::class)
                ->and($codec->result)->toBeInstanceOf(NodeListCodecV1::class);

            foreach ([new ListNodes, new ListNodes(NodeId::fromString(ListingWorld::NEWS), 1)] as $index => $query) {
                queryRoundTrip(new ListNodesCodecV1, $query, 'node.list.v1.json', 'query '.$index);
            }

            foreach ([new NodeList(ListingWorld::nodes(), NodeId::fromString(ListingWorld::CULTURE)), new NodeList([], null)] as $index => $result) {
                queryRoundTrip(new NodeListCodecV1, $result, 'node.list.result.v1.json', 'result '.$index);
            }
        },
        'path.resolve v1' => static function (QueryCodec $codec): void {
            $world = new ResolveWorld()->place(ResolveWorld::PLACEMENT, ResolveWorld::SECTION, 'harbour', window: ResolveWorld::window(-1, 5));
            $closed = new ResolveWorld()->place(ResolveWorld::PLACEMENT, ResolveWorld::SECTION, 'harbour', window: ResolveWorld::window(-5, -1));

            expect($codec->query)->toBeInstanceOf(ResolvePathCodecV1::class)
                ->and($codec->result)->toBeInstanceOf(ResolvedPathCodecV1::class);

            foreach ([
                new ResolvePath(new Host('North.Example:8080'), new Locale('en-gb'), new RequestPath('/nyheder/harbour')),
                new ResolvePath(new Host('north.example'), new Locale('da'), new RequestPath('/')),
            ] as $index => $query) {
                queryRoundTrip(new ResolvePathCodecV1, $query, 'path.resolve.v1.json', 'query '.$index);
            }

            foreach ([
                $world->resolve('north.example', '/nyheder/harbour'),
                $world->resolve('south.example', '/national/harbour'),
                $world->resolve('north.example', '/nyheder/pier'),
                $closed->resolve('north.example', '/nyheder/harbour'),
                new ResolveWorld()->resolve('nowhere.example', '/nyheder/harbour'),
            ] as $index => $result) {
                queryRoundTrip(new ResolvedPathCodecV1, $result, 'path.resolve.result.v1.json', 'result '.$index);
            }
        },
        'role.list v1' => static function (QueryCodec $codec): void {
            expect($codec->query)->toBeInstanceOf(ListRolesCodecV1::class)
                ->and($codec->result)->toBeInstanceOf(RoleListCodecV1::class);

            foreach ([new ListRoles, new ListRoles(RoleId::fromString(ListingWorld::ADMIN_ROLE), 2)] as $index => $query) {
                queryRoundTrip(new ListRolesCodecV1, $query, 'role.list.v1.json', 'query '.$index);
            }

            foreach ([new RoleList(ListingWorld::roles(), null), new RoleList([], RoleId::fromString(ListingWorld::DESK))] as $index => $result) {
                queryRoundTrip(new RoleListCodecV1, $result, 'role.list.result.v1.json', 'result '.$index);
            }
        },
    ];
}

/**
 * The value at the keys of a decoded document, or null where there is none.
 */
function queryValueAt(mixed $document, string ...$keys): mixed
{
    foreach ($keys as $key) {
        $document = is_array($document) ? ($document[$key] ?? null) : null;
    }

    return $document;
}

/**
 * A document of path.resolve: a valid one with the keys given replacing or adding to it, and the
 * keys named in $omit left out.
 *
 * @param  array<string, mixed>  $changes
 * @param  list<string>  $omit
 */
function resolveDocument(array $changes = [], array $omit = []): string
{
    $document = [...['host' => 'north.example', 'locale' => 'da', 'path' => '/nyheder/harbour'], ...$changes];

    foreach ($omit as $key) {
        unset($document[$key]);
    }

    return json_encode($document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/**
 * The committed schema of a binding of ProtocolSchemas::queries().
 */
function querySchemaFile(string $schema): string
{
    $binding = array_first(array_filter(ProtocolSchemas::queries(), static fn (SchemaBinding $each): bool => $each->schema === $schema))
        ?? throw new LogicException($schema.' is not the schema of a kernel query or result.');
    $contents = file_get_contents(Phpstan::root().'/'.$binding->path());

    return $contents === false ? throw new RuntimeException('The schema '.$schema.' cannot be read.') : $contents;
}

/**
 * Whether the JSON Schema accepts the document, by opis.
 */
function querySchemaAccepts(string $schema, string $document): bool
{
    $validator = new Validator;
    // RFC 3339 requires the offset of a date-time; opis's own format makes it optional.
    $validator->parser()->getFormatResolver()?->registerCallable('string', 'date-time', static fn (string $value): bool => preg_match(QUERY_OFFSET, $value) === 1 && DateTimeFormats::dateTime($value));

    return ! $validator->validate(json_decode($document, false, 512, JSON_THROW_ON_ERROR), querySchemaFile($schema))->error() instanceof ValidationError;
}

/**
 * The schema file of the query's document and of its result, by the codec's name and version.
 *
 * @return array{string, string}
 */
function querySchemas(QueryCodec $codec): array
{
    $file = $codec->name->value.'.v'.$codec->version.'.json';

    return [$file, $codec->name->value.'.result.v'.$codec->version.'.json'];
}

/**
 * Encodes the DTO, checks that the codec reads back an equal DTO that it writes the same, and that
 * the schema accepts what it wrote.
 *
 * @template TDto of object
 *
 * @param  JsonCodec<TDto>  $codec
 * @param  TDto  $dto
 */
function queryRoundTrip(JsonCodec $codec, object $dto, string $schema, string $at): void
{
    $json = $codec->encode($dto, ClassificationAccess::Sensitive);
    $decoded = $codec->decode($json, ClassificationAccess::Sensitive);

    expect($decoded)->toEqual($dto, $at.': the DTO read back')
        ->and($codec->encode($decoded, ClassificationAccess::Sensitive))->toBe($json, $at.': what it writes again')
        ->and(querySchemaAccepts($schema, $json))->toBeTrue($at.': the JSON Schema on '.$json);
}

/**
 * Encodes the DTO for a reader below personal access and checks that the profiles' values are left
 * out, that the codec reads back the DTO as that reader sees it, that it refuses the document a
 * personal reader gets, and that the schema accepts what it wrote (PRD 12.2).
 *
 * @template TDto of object
 *
 * @param  JsonCodec<TDto>  $codec
 * @param  TDto  $dto
 */
function queryWithheld(JsonCodec $codec, object $dto, string $schema, string $at): void
{
    $json = $codec->encode($dto, ClassificationAccess::Confidential);
    $full = $codec->encode($dto, ClassificationAccess::Personal);

    expect($json)->not->toContain('@', $at.': no email below personal')
        ->and($codec->encode($codec->decode($json, ClassificationAccess::Confidential), ClassificationAccess::Personal))->toBe($json, $at.': what a reader below personal reads back')
        ->and(querySchemaAccepts($schema, $json))->toBeTrue($at.': the JSON Schema on '.$json)
        ->and($full === $json || queryRefusedAt($codec, $full) !== null)->toBeTrue($at.': the document of a personal reader is refused below personal');
}

/**
 * The path at which the PHP codec refuses a document, '' for the document itself, or null when it
 * accepts it.
 *
 * @template TDto of object
 *
 * @param  JsonCodec<TDto>  $codec
 */
function queryRefusedAt(JsonCodec $codec, string $document): ?string
{
    try {
        $codec->decode($document, ClassificationAccess::Public);
    } catch (DecodingFailed $failure) {
        return $failure->path?->toString() ?? '';
    }

    return null;
}

/**
 * @return array<string, array{QueryCodec}>
 */
function kernelQueryCodecs(): array
{
    $codecs = [];

    foreach (KernelQueryCodecs::all() as $codec) {
        $codecs[$codec->name->value.' v'.$codec->version] = [$codec];
    }

    return $codecs;
}

it('has fixtures for every codec KernelQueryCodecs lists, and lists the codec of path.resolve', function (): void {
    expect(array_keys(kernelQueryCodecs()))->toBe(array_keys(queryFixtures()))
        ->and(array_keys(kernelQueryCodecs()))->toContain('path.resolve v1');
});

it('decodes every query and result it encodes into an equal one, and writes what the committed JSON Schema accepts', function (QueryCodec $codec): void {
    [$querySchema, $resultSchema] = querySchemas($codec);
    $roundTrips = queryFixtures()[$codec->name->value.' v'.$codec->version] ?? throw new LogicException('No fixtures.');

    expect(json_decode($codec->querySchema->json, true, 512, JSON_THROW_ON_ERROR))->toBe(json_decode(querySchemaFile($querySchema), true, 512, JSON_THROW_ON_ERROR))
        ->and(json_decode($codec->resultSchema->json, true, 512, JSON_THROW_ON_ERROR))->toBe(json_decode(querySchemaFile($resultSchema), true, 512, JSON_THROW_ON_ERROR));

    $roundTrips($codec);
})->with(kernelQueryCodecs(...));

it('writes the result of a resolved path with the entry, its fields and every step', function (): void {
    $world = new ResolveWorld()->place(ResolveWorld::PLACEMENT, ResolveWorld::SECTION, 'harbour', window: ResolveWorld::window(-1, 5));
    $codec = new ResolvedPathCodecV1;
    $document = json_decode($codec->encode($world->resolve('north.example', '/nyheder/harbour'), ClassificationAccess::Sensitive), true, 512, JSON_THROW_ON_ERROR);
    $missing = json_decode($codec->encode(new ResolveWorld()->resolve('nowhere.example', '/nyheder/harbour'), ClassificationAccess::Sensitive), true, 512, JSON_THROW_ON_ERROR);

    expect(is_array($document) ? array_keys($document) : null)->toBe(['content', 'explanation'])
        ->and(queryValueAt($document, 'content', 'entry'))->toBe(ResolveWorld::ENTRY)
        ->and(queryValueAt($document, 'content', 'node'))->toBe(ResolveWorld::SECTION)
        ->and(queryValueAt($document, 'content', 'type'))->toBe(ResolveWorld::ARTICLE)
        ->and(queryValueAt($document, 'content', 'fields') !== [] && is_array(queryValueAt($document, 'content', 'fields')))->toBeTrue()
        ->and(queryValueAt($document, 'explanation', 'outcome'))->toBe('resolved')
        ->and(is_array($missing) && array_key_exists('content', $missing) && $missing['content'] === null)->toBeTrue()
        ->and(queryValueAt($missing, 'explanation', 'outcome'))->toBe('unknown_host');
});

it('refuses every rule of path.resolve that its JSON Schema states, as the TypeScript validator and the schema do', function (): void {
    $codec = new ResolvePathCodecV1;
    $fixtures = [
        'valid' => [resolveDocument(), null],
        'a host with a port' => [resolveDocument(['host' => 'North.Example:8080']), null],
        'the root path' => [resolveDocument(['path' => '/']), null],
        'no host' => [resolveDocument(omit: ['host']), 'host'],
        'no locale' => [resolveDocument(omit: ['locale']), 'locale'],
        'no path' => [resolveDocument(omit: ['path']), 'path'],
        'an unknown key' => [resolveDocument(['site' => 'north']), ''],
        'a host that is not a DNS name' => [resolveDocument(['host' => 'north_example']), 'host'],
        'a locale that is not a language tag' => [resolveDocument(['locale' => 'danish']), 'locale'],
        'a path without a slash' => [resolveDocument(['path' => 'nyheder']), 'path'],
        'a path with a trailing slash' => [resolveDocument(['path' => '/nyheder/']), 'path'],
        'a host that is a number' => [resolveDocument(['host' => 7]), 'host'],
    ];
    $cases = [];

    foreach ($fixtures as $fixture => [$document, $path]) {
        expect(queryRefusedAt($codec, $document))->toBe($path, 'The PHP codec on '.$fixture)
            ->and(querySchemaAccepts('path.resolve.v1.json', $document))->toBe($path === null, 'The JSON Schema on '.$fixture);

        $cases[] = ['module' => 'protocol/ResolvePathV1', 'validator' => 'validateResolvePathV1', 'document' => $document];
    }

    $paths = array_values(array_map(static fn (array $fixture): ?string => $fixture[1], $fixtures));

    foreach (TypeScriptValidators::run(QUERY_TYPESCRIPT, $cases) as $index => $verdict) {
        expect($verdict['valid'] ? null : ($verdict['path'] ?? ''))->toBe($paths[$index], sprintf('The TypeScript validator on %s: %s', array_keys($fixtures)[$index], $verdict['reason'] ?? 'valid'));
    }
});
