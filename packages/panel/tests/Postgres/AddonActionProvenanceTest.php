<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Postgres;

use Cbox\Cms\Core\Pipeline\Actions\CommandPipeline;
use Cbox\Cms\Core\Tests\Pipeline\Tally\TallyId;
use Cbox\Cms\Core\Tests\Pipeline\Tally\TallyTable;
use Cbox\Cms\Core\Tests\Pipeline\Tally\TallyWorld;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;
use Cbox\Cms\Http\Inertia\Boundary\InertiaOutcome;
use Cbox\Cms\Panel\Domain\Dto\PanelBuild;
use Cbox\Cms\Panel\Tests\Contributions\ContributionWorld;
use Cbox\Cms\Panel\Tests\Contributions\ShellWorld;
use Cbox\Cms\Panel\Tests\PanelSignIn;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;
use stdClass;

/**
 * The provenance of an addon's action (section 3.3 and 5.5 of the panel extension architecture),
 * on real Postgres and Valkey: the panel's host runs the action's command through the Inertia
 * profile below the panel, as the person who signed in, with the envelope it sends for a
 * contribution of an addon, whose provenance names the contribution as a source,
 * `addon:<namespace>:<contribution>`; the real pipeline over TallyWorld commits the test-only
 * tally.add, and the changeset records the provenance. It is attribution, not a control: the
 * command runs as the viewer whatever the envelope says.
 */
final class AddonActionProvenanceTest extends TestCase
{
    use PanelSignIn;
    use RealPostgres;

    /** The envelope the host sends for the contribution ADD, as js/panel/src/host/commands.ts writes it. */
    private const string PROVENANCE = 'addon:tally:'.ContributionWorld::ADD;

    private ?ShellWorld $shell = null;

    private ?TallyWorld $tally = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $app = $this->app ?? self::fail('No application.');
        $tally = new TallyWorld;
        $this->shell = new ShellWorld($app, auditor: $tally->actor);
        $this->tally = $tally;
        $this->giveLocalAccount($tally->actor, ShellWorld::AUDITOR_EMAIL);
        $app->instance(CommandPipeline::class, $tally->pipeline());
    }

    #[Override]
    protected function tearDown(): void
    {
        TallyWorld::cleanUp();
        $this->shell?->cleanUp();
        $this->shell = null;
        $this->tally = null;

        parent::tearDown();
    }

    #[Test]
    public function it_records_the_provenance_of_the_contribution_on_the_changeset_the_action_s_command_commits(): void
    {
        $tally = $this->tally ?? self::fail('No world.');
        $session = $this->signInAs(ShellWorld::AUDITOR_EMAIL);

        // The start page binds Laravel's session, whose cookie and CSRF token the post carries, to
        // the CMS session, as the browser's does.
        $this->withCredentials()->visitPanel($session, '/cms')->assertOk();

        $response = $this->withCredentials()
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => app(PanelBuild::class)->version, 'X-CSRF-TOKEN' => app('session.store')->token()])
            ->from('/cms')
            ->postJson('/cms/commands/tally.add/v1', [
                'envelope' => [
                    'dry_run' => false,
                    'idempotency_key' => 'panel-action-1',
                    'wait_level' => 'commit',
                    'provenance' => ['sources' => [self::PROVENANCE]],
                ],
                'command' => ['tally' => $tally->actor->toString()],
            ]);

        $response->assertStatus(InertiaOutcome::STATUS)->assertRedirect('/cms');

        $receipt = $this->withCredentials()->visitPanel($session, '/cms')->assertOk()->json('flash.'.InertiaOutcome::RECEIPT);

        self::assertSame('committed', is_array($receipt) ? $receipt['outcome'] ?? null : null);
        self::assertSame([1, 1], TallyTable::row(TallyId::fromString($tally->actor->toString())));

        $changesets = StorageTables::superuser()->table('changesets')->get(['actor_id', 'provenance_sources', 'provenance_model', 'command']);
        $changeset = $changesets->first();
        $changeset = $changeset instanceof stdClass ? get_object_vars($changeset) : [];
        $sources = $changeset['provenance_sources'] ?? null;

        self::assertCount(1, $changesets);
        self::assertSame('tally.add', $changeset['command'] ?? null);
        self::assertSame($tally->actor->toString(), $changeset['actor_id'] ?? null);
        self::assertSame([self::PROVENANCE], is_string($sources) ? json_decode($sources, true) : $sources);
        self::assertNull($changeset['provenance_model'] ?? null);
    }
}
