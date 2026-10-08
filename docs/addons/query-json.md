---
title: Query JSON
weight: 50
description: "The JSON form of the kernel's queries and their results, two JSON Schemas per query and version in packages/core/resources/schemas/queries, and the generated codecs every surface reads the queries and writes their results with."
---

# Query JSON

<!-- extension-point: packages/core/resources/schemas/queries/path.resolve.v1.json -->
<!-- extension-point: packages/core/resources/schemas/queries/path.resolve.result.v1.json -->

A caller sends a query to a surface as a JSON document: the query parameter `query` of a REST read and the `query` argument of an MCP tool. The surface answers with the query's result as a JSON document. The JSON form of each of the kernel's queries is fixed by two JSON Schemas of draft 2020-12 per query and version in [`packages/core/resources/schemas/queries`](../../packages/core/resources/schemas/queries): `<query>.v<version>.json` for the query's document and `<query>.result.v<version>.json` for its result. Each is read and written only by the codec generated from it (GUARDRAILS 2.2). All of it is `#[Experimental]`.

| Query | Schemas | Codecs | PHP form |
|---|---|---|---|
| `path.resolve` | `path.resolve.v1.json`, `path.resolve.result.v1.json` | `ResolvePathCodecV1`, `ResolvedPathCodecV1` | `ResolvePath` and `ResolvedPath` |
| `role.list` | `role.list.v1.json`, `role.list.result.v1.json` | `ListRolesCodecV1`, `RoleListCodecV1` | `ListRoles` and `RoleList` |
| `grant.list` | `grant.list.v1.json`, `grant.list.result.v1.json` | `ListGrantsCodecV1`, `GrantListCodecV1` | `ListGrants` and `GrantList` |
| `actor.list` | `actor.list.v1.json`, `actor.list.result.v1.json` | `ListActorsCodecV1`, `ActorListCodecV1` | `ListActors` and `ActorList` |
| `actor.me` | `actor.me.v1.json`, `actor.me.result.v1.json` | `WhoAmICodecV1`, `ActorMeCodecV1` | `WhoAmI` and `ActorMe` |
| `action.list` | `action.list.v1.json`, `action.list.result.v1.json` | `ListActionsCodecV1`, `ActionListCodecV1` | `ListActions` and `ActionList` |
| `node.list` | `node.list.v1.json`, `node.list.result.v1.json` | `ListNodesCodecV1`, `NodeListCodecV1` | `ListNodes` and `NodeList` |

`path.resolve` is the public query that resolves a URL to the placement it shows (PRD 5.9). It is exposed on no surface: the delivery API serves it as `GET /v1/resolve` (see [delivery JSON](delivery-json.md)), and `cms:explain` prints its explanation. Its result holds the entry the placement places, with its node, its type and the fields of its released revision in the input form of [command JSON](command-json.md), or `null`, and the explanation of the resolution as `path-explanation.v1.json` describes it. The query pipeline leaves out every field above the reader's classification access before the codec writes the result. The four listing queries are described in [access queries](access-queries.md), and `actor.me`, the query of one's own self the who-am-I page reads, in [panel pages](panel/pages.md).

## The documents

A document is an object of the query's keys in snake_case and no other key. Every key is required unless its schema gives it a default. A host is a DNS name with an optional port, written back in lowercase; a locale is a language tag, written back as a command's is; a path starts with a slash. A result is written with keys sorted and no whitespace, and every key of its schema is present, `null` where there is no value, except a value classified above public.

A property of a result whose schema has `"x-cms-classification"`, a class above `public` such as `personal`, is withheld from a reader whose classification access does not allow that class (PRD 12.2): it is not in the object's `required` and has no default, and it is never null. The codec writes it at the reader's access, through the result's `visibleTo()`, and leaves it out below it; it reads it as required where the reader's access allows the class and refuses it in a document for any other reader. The result's class holds `Cbox\Cms\Contracts\Fields\Omitted` for a withheld value, so the constructor argument is the value's type or `Omitted`. The profiles of [access queries](access-queries.md) are personal this way.

## The codecs are generated

`composer generate:protocol` reads the schemas and writes the codecs into `packages/core/src/Codecs/Boundary/Generated`, bound to the query's class and the result's class, with `KernelQueryCodecs`. The codec of a query's document carries its schema as `SCHEMA` and builds the query's `QueryCodec`: the query's name and version, the codec of the query and its schema, and the codec of the result and its schema, which the result's codec carries as `SCHEMA`. `KernelQueryCodecs::all()` lists the `QueryCodec` of each kernel query, and the core registers each under the container tag `QueryCodecs::TAG`, so REST and MCP read every kernel query an action exposes, write its result at the reader's classification access, and describe both with their schemas. `cms:generate` writes a TypeScript module per schema into `resources/js/cms/generated/protocol`, such as `ResolvePathV1.ts` and `ResolvedPathV1.ts`, with the document's types and a validator, `validateResolvePathV1()`, that refuses what the PHP codec refuses at the same value.

`composer check:generated` fails when the committed codecs or TypeScript are not what the schemas generate. A REST route or an MCP tool of a query without a `QueryCodec` fails cms:build or is left undescribed, and the surface contract tests fail for every surface such a query lists. `decode()` refuses a document with `Cbox\Cms\Core\Codecs\Domain\DecodingFailed`: `json_malformed` for a document that is not a JSON object, and `json_invalid` with the path of the value for a key that is missing, a key the query does not have and a value that breaks a rule of the schema or of the query's class. A change of the JSON form is a change of the schema, and one that is not backwards compatible is a new version of the query with schema files and codecs of its own.

The example is in the `Unit` suite:

<!-- example: examples/Unit/Protocol/QueryJsonTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Core\Codecs\Boundary\Generated\KernelQueryCodecs;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ResolvedPathCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ResolvePathCodecV1;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCodec;
use Cbox\Cms\Core\Routing\Domain\Dto\ResolvedPath;
use Cbox\Cms\Core\Routing\Domain\Queries\ResolvePath;
use Cbox\Cms\Core\Routing\Domain\ResolveOutcome;

// A caller sends path.resolve as JSON: the host, the locale and the path. The generated codec of
// path.resolve.v1.json reads it into the query, and the codec of path.resolve.result.v1.json
// writes the result. KernelQueryCodecs lists the QueryCodec of each kernel query: both codecs and
// both schemas, which every surface reads, writes and describes the query with.

it('reads path.resolve from JSON and writes it back as canonical JSON', function (): void {
    $codec = new ResolvePathCodecV1;
    $query = $codec->decode('{"path":"/news/harbour","locale":"en-gb","host":"North.Example"}', ClassificationAccess::Public);

    expect($query)->toBeInstanceOf(ResolvePath::class)
        ->and($query->host->value)->toBe('north.example')
        ->and($codec->encode($query, ClassificationAccess::Public))->toBe('{"host":"north.example","locale":"en-GB","path":"/news/harbour"}')
        ->and(static fn (): ResolvePath => $codec->decode('{"host":"north.example","locale":"da","path":"news"}', ClassificationAccess::Public))
        ->toThrow(DecodingFailed::class);
});

it('reads and writes the result of path.resolve, and lists the codecs of each kernel query', function (): void {
    $codec = new ResolvedPathCodecV1;
    $json = '{"content":null,"explanation":{"canonical":null,"mount":null,"node":null,"outcome":"unknown_host","placement":null,"route":null,'
        .'"site":{"handle":null,"host":"nowhere.example","locale":"da","locale_published":false,"site":null},"visibility":null}}';
    $result = $codec->decode($json, ClassificationAccess::Public);
    $queries = array_map(static fn (QueryCodec $each): string => $each->name->value.' v'.$each->version, KernelQueryCodecs::all());

    expect($result)->toBeInstanceOf(ResolvedPath::class)
        ->and($result->outcome())->toBe(ResolveOutcome::UnknownHost)
        ->and($codec->encode($result, ClassificationAccess::Public))->toBe($json)
        ->and($queries)->toContain('path.resolve v1')
        ->and(array_first(array_filter(KernelQueryCodecs::all(), static fn (QueryCodec $each): bool => $each->name->value === 'path.resolve'))?->result)->toBeInstanceOf(ResolvedPathCodecV1::class);
});
```
