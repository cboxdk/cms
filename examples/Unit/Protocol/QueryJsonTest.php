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
