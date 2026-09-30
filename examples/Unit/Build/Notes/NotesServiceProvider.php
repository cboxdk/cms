<?php

declare(strict_types=1);

namespace Examples\Unit\Build\Notes;

use Cbox\Cms\Contracts\Build\DeclaresScanRoots;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Contracts\Codecs\JsonSchema;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCodec;
use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Examples\Unit\Build\Notes\Boundary\FindNoteCodec;
use Examples\Unit\Build\Notes\Boundary\FoundNoteCodec;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * The service provider of the package acme/cms-notes. Its scan root is the directory it lies in,
 * so cms:build registers the command, the hook, the query and its action next to it. The query's
 * action is on REST, so the provider registers the query's codecs, with their JSON Schemas, under
 * QueryCodecs::TAG, which the REST surface reads the query and writes its result with and
 * cms:build describes its route with.
 */
final class NotesServiceProvider extends ServiceProvider implements DeclaresScanRoots
{
    #[Override]
    public function register(): void
    {
        $this->app->bind('acme.notes.find.codec', static fn (): QueryCodec => new QueryCodec(
            new CommandName('note.find'),
            1,
            new FindNoteCodec,
            new JsonSchema(FindNoteCodec::SCHEMA),
            new FoundNoteCodec,
            new JsonSchema(FoundNoteCodec::SCHEMA),
        ));
        $this->app->tag(['acme.notes.find.codec'], QueryCodecs::TAG);
    }

    public function scanRoots(): array
    {
        return [new ScanRoot('acme/cms-notes', __DIR__)];
    }
}
