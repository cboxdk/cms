<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Entries;

use Cbox\Cms\Contracts\Codecs\RecordCodecs;
use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Core\Entries\Actions\GetEntryAction;
use Cbox\Cms\Core\Entries\Actions\ListEntriesAction;
use Cbox\Cms\Core\Entries\Actions\PreviewEntryAction;
use Cbox\Cms\Core\Entries\Domain\Queries\GetEntry;
use Cbox\Cms\Core\Entries\Domain\Queries\ListEntries;
use Cbox\Cms\Core\Entries\Domain\Queries\PreviewEntry;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Core\Reads\Domain\Dto\QuerySettings;
use Cbox\Cms\Core\Reads\Domain\ReadableFields;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeAccessResolver;
use Cbox\Cms\Core\Tests\Access\Fakes\FakePermissions;
use Cbox\Cms\Core\Tests\Entries\Fakes\FakeEntryPreviews;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryActions;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryAuthorizer;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryTransaction;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeReadAudit;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeQueryBinding;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Identity\FakeIdentity;
use Cbox\Cms\Testkit\Identity\ServiceCredentialSpec;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use DateInterval;

/**
 * entry.get, entry.list and entry.preview through the real QueryPipeline with fakes for its ports (GUARDRAILS 9):
 * the identity, the access resolver, the read audit and the read transaction, the query
 * authorizer deciding from FakePermissions as the Postgres one does, the workbench's type catalog
 * for the stripping of fields and the workbench's record codecs for the preview, and the three
 * actions over the EntryListingWorld's fakes as ADMIN reads them. A reader is a service actor with a sensitive ceiling whose regions reach NORTH and
 * SOUTH and whose classification access a test gives; it holds no role, because every actor may
 * run the entry reads. The read audit keeps what the reads record.
 */
final readonly class EntryReadsActionWorld
{
    public FakeIdentity $identity;

    public FakeAccessResolver $access;

    public FakeQueryAuthorizer $authorizer;

    public FakeReadAudit $audit;

    private FakeClock $clock;

    public function __construct()
    {
        $this->clock = new FakeClock;
        $this->identity = new FakeIdentity($this->clock);
        $this->access = new FakeAccessResolver;
        $this->authorizer = FakeQueryAuthorizer::granting(new FakePermissions([EntryListingWorld::NORTH => new NodePath(StorageTables::label(EntryListingWorld::NORTH))]));
        $this->audit = new FakeReadAudit;
    }

    public function reader(ClassificationAccess $access): TransportCredential
    {
        $actor = $this->identity->addActor(ActorClass::Service)->id;
        $this->access->grant($actor, [
            new AccessRegion(new NodePath(StorageTables::label(EntryListingWorld::NORTH))),
            new AccessRegion(new NodePath(StorageTables::label(EntryListingWorld::SOUTH))),
        ], $access);

        return $this->identity->issue(new ServiceCredentialSpec($actor, IssuerKind::Service, ClassificationAccess::Sensitive, $this->clock->now()->add(new DateInterval('P1D'))));
    }

    /**
     * @param  FakeEntryPreviews|null  $previews  what entry.preview reads; null for the EntryListingWorld's as ADMIN reads it
     */
    public function read(Query $query, ?TransportCredential $credential, ?FakeEntryPreviews $previews = null): QueryResult
    {
        return $this->pipeline($previews)->run(new QueryCall($query, $credential));
    }

    /**
     * @param  FakeEntryPreviews|null  $previews  what entry.preview reads; null for the EntryListingWorld's as ADMIN reads it
     */
    public function pipeline(?FakeEntryPreviews $previews = null): QueryPipeline
    {
        $listing = EntryListingWorld::listing(EntryListingWorld::ADMIN);

        return new QueryPipeline(
            new FakeQueryActions([
                GetEntry::class => ProbeQueryBinding::of(new GetEntryAction($listing), 'entry.get', 1),
                ListEntries::class => ProbeQueryBinding::of(new ListEntriesAction($listing), 'entry.list', 1),
                PreviewEntry::class => ProbeQueryBinding::of(
                    new PreviewEntryAction($previews ?? EntryListingWorld::previews(EntryListingWorld::ADMIN), app(TypeCatalog::class), app(RecordCodecs::class)),
                    'entry.preview',
                    1,
                ),
            ]),
            $this->identity,
            $this->access,
            $this->authorizer,
            new QuerySettings(new QueryCost(200), new QueryCost(1000)),
            new ReadableFields(app(TypeCatalog::class)),
            $this->audit,
            new FakeQueryTransaction(sessions: $this->access),
            new PipelineTelemetry(new FakeTelemetry, $this->clock, new FakeStopwatch),
        );
    }
}
