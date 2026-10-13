---
title: A site goes live
weight: 31
description: The walking skeleton of MILESTONES 1.6 that takes a site from an empty database to a page the public reads through the maintenance commands, the panel, cms:run and REST alone, with no test fixture writer, and the test that holds it.
---

# A site goes live

Block B2 adds the commands for sites, nodes, routes and mounts, and reconciles the sites from the configuration, so that a real site can go live without test fixtures (MILESTONES 1.6). This page is the proof: one test takes a site from an empty database to a page the public reads, and every write in it is a command an installation runs from its console, its panel or its API. Nothing in it writes a row by hand, and no fixture writer of the testkit is named.

## The path

1. **The installation.** `cms:partitions:maintain` keeps the partitions ahead of the clock, `cms:install` creates the installation operator, `cms:build` compiles the registry, and `cms:sites:sync` registers the site of `cbox-cms.sites` with its root node and its locale, as `composer dev:prepare` and a deploy do ([Maintenance commands](maintenance-commands.md)). The line `cms:sites:sync` prints names the site and its root node, which the next steps use.
2. **The first administrator.** `cms:staff:create` registers a member of staff with a password, and `cms:access:bootstrap` gives it the bootstrap role on the site's root, once, in the maintenance process ([the access bootstrap](maintenance-commands.md#the-access-bootstrap)).
3. **The editor.** `cms:service:create` registers a service actor, with the administrator responsible for it, through `actor.register` and `actor.activate`, and `cms:credential:issue` issues its credential ([cms:service:create and cms:credential:issue](maintenance-commands.md#cmsservicecreate-and-cmscredentialissue)). The administrator logs in to the panel and posts `role.create` and `grant.assign` to the panel's Inertia profile with its session, as the panel's forms do: an editor role with the structure and content commands, granted to the editor on the site's root. The escalation guard holds: the administrator gives only what it holds.
4. **The structure and the content, as the editor.** `node.create` and `node.set_route` make a section with the route `/nyheder`; `mount.create` and `node.set_route` a mount of the section with the route `/arkiv`; `entry.create` an article below the section; `placement.create` its placement with the slug `harbour`; and `entry.publish` releases its revision and opens the placement's window. Each runs through `cms:run` with the editor's credential in `cbox-cms.cli.credential` or through `POST /v1/commands/<name>/v1` with it as the Bearer token, so both surfaces carry the same editor and the changesets record `cli` and `rest` as their surfaces.
5. **The public reads it.** `GET /v1/resolve` answers 200 on `/nyheder/harbour` with the article's record and the canonical URL on the site's origin, and 301 on `/arkiv/harbour` with `Location` pointing at `/nyheder/harbour`, with the mount's node among the content keys, because the site's own placement of the article suppresses the one it shows through the mount (PRD 5.8, [the delivery API](delivery.md)).

The clock is the system's, as a deploy's is: the partitions the maintenance keeps ahead cover every write, and the delivery reads at its own time, after the publish. The panel's build is the test's `FixtureBuild`, which stands in for `npm run build` and writes no data. The test runs in the Postgres suite on this checkout's own test database and Valkey prefix ([Services and isolation](services.md)).

## What the test holds

The test fails when a step of the path needs a fixture: a site that `cms:sites:sync` does not register, a first grant the bootstrap cannot give, an editor the panel cannot grant, a command one of the two surfaces refuses the editor, or a path the delivery does not resolve. `grep -c -E 'FixtureWriters|PostgresIdentitySeeder|PostgresAccessFixtures' tests/Postgres/WalkingSkeleton/SiteGoesLiveTest.php` prints 0.

<!-- example: tests/Postgres/WalkingSkeleton/SiteGoesLiveTest.php -->
```php
<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Postgres\WalkingSkeleton;

use Cbox\Cms\Cli\Boundary\CliCredential;
use Cbox\Cms\Contracts\Identity\BreachedPasswords;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Registry\Boundary\OpenApiJson;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;
use Cbox\Cms\Http\Delivery\Boundary\DeliveryOutput;
use Cbox\Cms\Http\Inertia\Boundary\InertiaOutcome;
use Cbox\Cms\Http\Rest\RestRoutes;
use Cbox\Cms\Identity\Sessions\Domain\Dto\SessionCookie;
use Cbox\Cms\Panel\Boundary\LoginForm;
use Cbox\Cms\Panel\Domain\Dto\PanelBuild;
use Cbox\Cms\Panel\Tests\FixtureBuild;
use Cbox\Cms\Testkit\Identity\FakeBreachedPasswords;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Testkit\Valkey\RealValkey;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Illuminate\Testing\TestResponse;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * MILESTONES 1.6, block B2: a real site goes live through commands alone, with no test fixture
 * writer (PRD 5.8 to 5.10, 5.16, 6.1, 8.9, 11.14). Everything below is what an installation does
 * from its console, its panel and its API:
 *
 * 1. cms:partitions:maintain keeps the partitions ahead of the clock, cms:install creates the
 *    installation operator, cms:build compiles the registry, and cms:sites:sync registers the site
 *    of cbox-cms.sites with its root node and its locale, as a deploy does.
 * 2. cms:staff:create registers the first member of staff, and cms:access:bootstrap gives it the
 *    bootstrap role on the site's root, in the maintenance process (B1-T24).
 * 3. The administrator logs in to the panel and, as the panel's forms do, posts role.create and
 *    grant.assign to the Inertia profile with its session: an editor role, granted on the site's
 *    root to an editor, a service actor that cms:service:create registered through actor.register
 *    with the administrator responsible for it, and whose credential cms:credential:issue issued.
 * 4. As that editor, node.create and node.set_route make a section with a route, mount.create and
 *    node.set_route a mount of the section with a route of its own, entry.create an article,
 *    placement.create its placement below the section, and entry.publish releases it live, each
 *    through cms:run with the editor's credential and through REST with it as the Bearer token.
 * 5. GET /v1/resolve answers 200 on the section's path with the article among the content keys,
 *    and 301 on the mount's path to the section's path, because the site's own placement of the
 *    article suppresses the mounted one (PRD 5.8), with the mount's node among the content keys.
 *
 * The clock is the system's, as a deploy's is, so the partitions the maintenance keeps ahead cover
 * every write, and the delivery reads at its own time, after the publish. The panel's build is the
 * FixtureBuild, which stands in for `npm run build` and writes no data; the breach check and the
 * hashing are bound for the test, as GettingStartedPathTest binds them.
 */
final class SiteGoesLiveTest extends TestCase
{
    use RealPostgres;
    use RealValkey;

    private const string HOST = 'north.example';

    private const string ADMIN_EMAIL = 'mette.holm@example.com';

    private const string PASSWORD = 'the harbour opens at dawn, every day';

    private const string SECTION = '0192a0c0-0000-7000-8000-0000000069a1';

    private const string MOUNT = '0192a0c0-0000-7000-8000-0000000069a2';

    private const string ENTRY = '0192a0c0-0000-7000-8000-0000000069e1';

    private const string PLACEMENT = '0192a0c0-0000-7000-8000-0000000069c1';

    private const string ROLE = '0192a0c0-0000-7000-8000-0000000069b1';

    private const string GRANT = '0192a0c0-0000-7000-8000-0000000069b2';

    /** What the editor may do: the structure, the content and the read the delivery makes. */
    private const array EDITOR_PERMISSIONS = ['node.create', 'node.set_route', 'mount.create', 'entry.create', 'placement.create', 'entry.publish', 'path.resolve'];

    private ?FixtureBuild $build = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cbox-cms.sites', ['north' => ['origin' => 'https://'.self::HOST, 'locales' => ['da']]]);
        config()->set('cbox-cms.delivery.max_age_seconds', 300);
        config()->set('cbox-cms.identity.passwords.argon2id', ['memory_kib' => 1024, 'time' => 1]);
        app()->instance(BreachedPasswords::class, new FakeBreachedPasswords(new Password('Summer2026!Summer')));
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->build?->remove();
        $this->build = null;
        DB::purge(StorageTables::SUPERUSER);

        parent::tearDown();
    }

    #[Test]
    public function it_takes_a_site_live_through_the_maintenance_commands_the_panel_cms_run_and_rest_and_serves_it_on_its_path_and_redirects_its_mount_to_it(): void
    {
        // 1. The installation and the site, as a deploy sets them up.
        $this->console('cms:partitions:maintain');
        $this->console('cms:install');
        $this->console('cms:build');
        [$site, $root] = $this->registeredSite();
        RestRoutes::register(app(Registrar::class), app(CompiledRegistry::class));
        app(Router::class)->getRoutes()->refreshNameLookups();
        $this->build = FixtureBuild::write();
        $this->build->bind(app());

        // 2. The first administrator, from the console.
        $admin = $this->administrator($root);

        // 3. The editor, a service actor the administrator is responsible for, with its credential
        // and, from the panel, its role and its grant on the site's root.
        $editor = $this->firstLine($this->console('cms:service:create', ['--email' => 'importer@example.com', '--name' => 'Importer', '--responsible' => $admin]));
        $credential = $this->firstLine($this->console('cms:credential:issue', ['actor' => $editor, '--days' => '30']));
        $session = $this->signIn();
        $this->start($session);
        $role = $this->inertia($session, 'role.create', ['role' => self::ROLE, 'handle' => 'editor', 'ceiling' => 'internal', 'permissions' => self::EDITOR_PERMISSIONS], 'site-live-role');
        $grant = $this->inertia($session, 'grant.assign', ['grant' => self::GRANT, 'actor' => $editor, 'role' => self::ROLE, 'node' => $root, 'effect' => 'allow'], 'site-live-grant');

        self::assertSame('committed', $role['outcome'] ?? null);
        self::assertSame('committed', $grant['outcome'] ?? null);

        // 4. The structure and the content, as the editor, through cms:run and REST.
        config()->set(CliCredential::CONFIG_KEY, $credential);
        $type = app(TypeCatalog::class)->named(new TypeName('app:fixture_article'))?->id->toString() ?? self::fail('The workbench has no article type.');
        $receipts = [
            'node.create' => $this->cli('node.create', ['node' => self::SECTION, 'parent' => $root, 'kind' => 'section'], 'site-live-section'),
            'node.set_route' => $this->rest($credential, 'node.set_route', ['node' => self::SECTION, 'version' => 1, 'site' => $site, 'locale' => 'da', 'route' => '/nyheder'], 'site-live-section-route'),
            'mount.create' => $this->cli('mount.create', ['node' => self::MOUNT, 'parent' => $root, 'source' => self::SECTION], 'site-live-mount'),
            'the mount\'s route' => $this->rest($credential, 'node.set_route', ['node' => self::MOUNT, 'version' => 1, 'site' => $site, 'locale' => 'da', 'route' => '/arkiv'], 'site-live-mount-route'),
            'entry.create' => $this->rest($credential, 'entry.create', [
                'entry' => self::ENTRY,
                'type' => $type,
                'home' => self::SECTION,
                'fields' => ['fixture_title' => 'The harbour opens', 'fixture_featured' => true, 'fixture_reading_minutes' => 4],
            ], 'site-live-entry'),
            'placement.create' => $this->cli('placement.create', ['placement' => self::PLACEMENT, 'entry' => self::ENTRY, 'node' => self::SECTION, 'site' => $site, 'slugs' => [['locale' => 'da', 'slug' => 'harbour']]], 'site-live-placement'),
            'entry.publish' => $this->rest($credential, 'entry.publish', ['entry' => self::ENTRY, 'version' => 1, 'revision' => 1, 'placement' => self::PLACEMENT, 'placement_version' => 1, 'locale' => 'da'], 'site-live-publish'),
        ];

        foreach ($receipts as $command => $receipt) {
            self::assertSame('committed', $receipt['outcome'] ?? null, $command);
        }

        $changesets = StorageTables::superuser()->table('changesets')->where('actor_id', $editor)->orderBy('changeset_id')->pluck('surface', 'command')->all();

        self::assertSame(['node.create', 'node.set_route', 'mount.create', 'entry.create', 'placement.create', 'entry.publish'], array_keys($changesets));
        self::assertSame(['cli', 'rest', 'cli', 'rest', 'cli', 'rest'], array_values($changesets));

        // 5. The public reads it on the site, and the mount redirects to the site's own placement.
        $onSite = $this->resolve('/nyheder/harbour');
        $onMount = $this->resolve('/arkiv/harbour');

        $onSite->assertOk()
            ->assertHeader(DeliveryOutput::SOURCE, 'miss')
            ->assertJsonPath('data.cms_id', self::ENTRY)
            ->assertJsonPath('data.fixture_title', 'The harbour opens')
            ->assertJsonPath('meta.canonical_url', 'https://'.self::HOST.'/nyheder/harbour');
        $onMount->assertStatus(301)
            ->assertHeader('Location', 'https://'.self::HOST.'/nyheder/harbour');
        self::assertSame('', $onMount->getContent());
        self::assertContains('e-'.self::ENTRY, explode(' ', (string) $onSite->headers->get(DeliveryOutput::SURROGATE_KEY)));
        self::assertContains('n-'.self::MOUNT, explode(' ', (string) $onMount->headers->get(DeliveryOutput::SURROGATE_KEY)));
    }

    /**
     * Runs an Artisan command of the installation in this process and gives its output, failing
     * the test with the output when it does not exit 0.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function console(string $command, array $arguments = []): string
    {
        $kernel = app(Kernel::class);
        $status = $kernel->call($command, $arguments);
        $output = $kernel->output();

        if ($status !== 0) {
            self::fail(sprintf("%s exited %d:\n%s", $command, $status, $output));
        }

        return $output;
    }

    /**
     * The first line of a command's output: what it printed alone on standard output, before the
     * notice on standard error, which the console's buffer holds too.
     */
    private function firstLine(string $output): string
    {
        return trim(explode("\n", trim($output), 2)[0]);
    }

    /**
     * The first UUIDv7 after a label in a command's output, such as "root node " of cms:sites:sync.
     */
    private function id(string $output, string $label): string
    {
        if (preg_match('/'.preg_quote($label, '/').'([0-9a-f-]{36})/', $output, $found) !== 1) {
            self::fail(sprintf("No id after \"%s\" in:\n%s", $label, $output));
        }

        return $found[1];
    }

    /**
     * The site registered from the configuration: the ids of the site and its root node.
     *
     * @return array{string, string}
     */
    private function registeredSite(): array
    {
        $output = $this->console('cms:sites:sync');

        return [$this->id($output, 'site '), $this->id($output, 'root node ')];
    }

    /**
     * The first administrator: a member of staff registered with cms:staff:create, the password
     * typed twice as in a terminal, given the bootstrap role on the node by cms:access:bootstrap;
     * its actor id.
     */
    private function administrator(string $root): string
    {
        $pending = $this->artisan('cms:staff:create', ['--email' => self::ADMIN_EMAIL, '--name' => 'Mette Holm']);
        self::assertInstanceOf(PendingCommand::class, $pending);
        $pending->expectsQuestion('Password', self::PASSWORD)
            ->expectsQuestion('Repeat the password', self::PASSWORD)
            ->assertExitCode(0)
            ->run();
        $admin = StorageTables::superuser()->table('actor_profiles')->where('email', self::ADMIN_EMAIL)->value('actor_id');
        self::assertIsString($admin, 'cms:staff:create registered no actor for the administrator.');

        $this->console('cms:access:bootstrap', ['actor' => $admin, 'node' => $root]);

        return $admin;
    }

    /**
     * Logs the administrator in through the panel's login form, as a browser does, and gives the
     * session cookie's value. The browser's cookies, Laravel's session among them, travel with
     * every later request of the test.
     */
    private function signIn(): string
    {
        $this->withCredentials()->get('/cms/login')->assertOk();
        $response = $this->withCredentials()->from('/cms/login')->post('/cms/login', [
            LoginForm::EMAIL => self::ADMIN_EMAIL,
            LoginForm::PASSWORD => self::PASSWORD,
            '_token' => app('session.store')->token(),
        ]);
        $response->assertRedirect('/cms');
        $cookie = $response->getCookie(app(SessionCookie::class)->name, false);
        self::assertInstanceOf(Cookie::class, $cookie, 'The login set no session cookie.');

        return (string) $cookie->getValue();
    }

    /**
     * A visit of the panel's start as an Inertia visit with the session, which binds Laravel's
     * session to it, as the browser's first page does; the page's JSON.
     *
     * @return TestResponse<Response>
     */
    private function start(string $session): TestResponse
    {
        return $this->withCredentials()
            ->withUnencryptedCookie(app(SessionCookie::class)->name, $session)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => app(PanelBuild::class)->version])
            ->get('/cms')
            ->assertOk();
    }

    /**
     * Posts a command to the panel's Inertia profile as the person who logged in, as the panel's
     * forms do, and gives the receipt the profile flashed for the page, read from the page the
     * form returns to; a rejection fails the test with the page's problem.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function inertia(string $session, string $command, array $document, string $key): array
    {
        $this->withCredentials()
            ->withUnencryptedCookie(app(SessionCookie::class)->name, $session)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => app(PanelBuild::class)->version, 'X-CSRF-TOKEN' => app('session.store')->token()])
            ->from('/cms')
            ->postJson('/cms/commands/'.$command.'/v1', ['envelope' => ['idempotency_key' => $key], 'command' => $document])
            ->assertStatus(InertiaOutcome::STATUS)
            ->assertRedirect('/cms');
        $page = $this->start($session);
        $problem = $page->json('props.'.InertiaOutcome::PROBLEM_PROP);

        if ($problem !== null) {
            self::fail(sprintf('%s was rejected on the panel: %s', $command, json_encode($problem)));
        }

        return $this->document($page->json('flash.'.InertiaOutcome::RECEIPT), sprintf('%s flashed no receipt.', $command));
    }

    /**
     * Runs a command through cms:run as the configured credential and gives its receipt; a
     * rejection fails the test with its problem.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function cli(string $command, array $document, string $key): array
    {
        $kernel = app(Kernel::class);
        $status = $kernel->call('cms:run', ['name' => $command, 'version' => '1', 'document' => json_encode($document, JSON_THROW_ON_ERROR), '--idempotency-key' => $key, '--json' => true]);

        if ($status !== 0) {
            self::fail(sprintf("cms:run %s exited %d:\n%s", $command, $status, $kernel->output()));
        }

        return $this->document(json_decode(trim($kernel->output()), true, 64, JSON_THROW_ON_ERROR), sprintf('cms:run %s printed no receipt.', $command));
    }

    /**
     * Posts a command to the REST API with the credential as the Bearer token and gives its
     * receipt; a rejection fails the test with its problem.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function rest(string $credential, string $command, array $document, string $key): array
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$credential, OpenApiJson::IDEMPOTENCY_KEY => $key])
            ->postJson('/v1/commands/'.$command.'/v1', $document);

        if ($response->getStatusCode() !== 200) {
            self::fail(sprintf("POST %s answered %d:\n%s", $command, $response->getStatusCode(), (string) $response->getContent()));
        }

        return $this->document(json_decode((string) $response->getContent(), true, 64, JSON_THROW_ON_ERROR), sprintf('POST %s answered no receipt.', $command));
    }

    /**
     * GET /v1/resolve of a path on the site, as the public asks for it.
     *
     * @return TestResponse<Response>
     */
    private function resolve(string $path): TestResponse
    {
        return $this->get('/v1/resolve?'.http_build_query(['site' => self::HOST, 'locale' => 'da', 'path' => $path]));
    }

    /**
     * A decoded JSON document as a map of its keys.
     *
     * @return array<string, mixed>
     */
    private function document(mixed $decoded, string $failure): array
    {
        if (! is_array($decoded)) {
            self::fail($failure);
        }

        $document = [];

        foreach ($decoded as $key => $value) {
            $document[(string) $key] = $value;
        }

        return $document;
    }
}
```
