<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Reads;

use Cbox\Cms\Contracts\Codecs\JsonSchema;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCodec;
use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Core\Reads\Domain\UnknownQuery;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeCountCodec;
use Cbox\Cms\Core\Tests\Reads\Probe\ReadProbeCodec;
use InvalidArgumentException;

/*
 * The codecs an exposed surface reads queries and writes their results with (GUARDRAILS 2.1, 2.2):
 * one per name and version, each with the JSON Schemas of the query and the result, registered
 * under QueryCodecs::TAG, which the core's service provider collects.
 */

function queryCodec(string $name = 'probe.read', int $version = 1): QueryCodec
{
    return new QueryCodec(new CommandName($name), $version, new ReadProbeCodec, new JsonSchema(ReadProbeCodec::SCHEMA), new ProbeCountCodec, new JsonSchema(ProbeCountCodec::SCHEMA));
}

it('gives the codecs of each version of a query, or null from find() when none reads it', function (): void {
    $first = queryCodec();
    $second = queryCodec(version: 2);
    $codecs = new QueryCodecs($first, $second);

    expect($codecs->for(new CommandName('probe.read'), 1))->toBe($first)
        ->and($codecs->for(new CommandName('probe.read'), 2))->toBe($second)
        ->and($codecs->find(new CommandName('probe.read'), 2))->toBe($second)
        ->and($codecs->find(new CommandName('probe.read'), 3))->toBeNull()
        ->and($codecs->find(new CommandName('probe.other'), 1))->toBeNull()
        ->and($first->querySchema->json)->toBe(ReadProbeCodec::SCHEMA)
        ->and($first->resultSchema->json)->toBe(ProbeCountCodec::SCHEMA);
});

it('refuses a version no codec reads, naming the query and the tag', function (): void {
    expect(static fn (): QueryCodec => new QueryCodecs(queryCodec())->for(new CommandName('probe.read'), 2))
        ->toThrow(UnknownQuery::class, 'No codec reads version 2 of the query probe.read, so no exposed surface can read it. Tag its QueryCodec with QueryCodecs::TAG.');
});

it('refuses two codecs for one version of a query', function (): void {
    expect(static fn (): QueryCodecs => new QueryCodecs(queryCodec(), queryCodec()))
        ->toThrow(InvalidArgumentException::class, 'Two codecs are registered for version 1 of the query probe.read.');
});

it('refuses codecs for version 0', function (): void {
    expect(static fn (): QueryCodec => queryCodec(version: 0))
        ->toThrow(UnknownQuery::class, 'The query probe.read has version 0. Versions start at 1.');
});

it('collects the codecs the container has under the tag, and none when nothing is tagged', function (): void {
    expect(app(QueryCodecs::class)->find(new CommandName('probe.read'), 1))->toBeNull();

    app()->bind('probe.read.codec', static fn (): QueryCodec => queryCodec());
    app()->tag(['probe.read.codec'], QueryCodecs::TAG);

    expect(app(QueryCodecs::class)->for(new CommandName('probe.read'), 1)->query)->toBeInstanceOf(ReadProbeCodec::class);
});
