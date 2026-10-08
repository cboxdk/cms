<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Postgres;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\BreachedPasswords;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Identity\Sessions\Domain\Dto\SessionCookie;
use Cbox\Cms\Panel\Boundary\LoginForm;
use Cbox\Cms\Panel\Boundary\PanelPages;
use Cbox\Cms\Panel\Domain\Dto\PanelBuild;
use Cbox\Cms\Panel\Tests\FixtureBuild;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Identity\FakeBreachedPasswords;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\Infrastructure\OwnerTruncation;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Testkit\Valkey\RealValkey;
use Cbox\Cms\Tests\Support\Tooling\ComposerScripts;
use Cbox\Cms\Tests\TestCase;
use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Testing\PendingCommand;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Cookie;
use Workbench\App\Providers\WorkbenchServiceProvider;

/**
 * The scripted part of the path in docs/getting-started/first-login.md (PRD 5.16, 5.10, 14.2), on
 * this checkout's test database and Valkey in place of the dev database: composer dev:prepare's
 * steps that run the workbench's Artisan, read from composer.json and run in order twice, the
 * second run changing nothing, neither a row of any table nor a partition nor the registry cache;
 * cms:staff:create, the password typed twice as in a terminal, which prints the new member of
 * staff's actor id; cms:access:bootstrap with that id and the root node of the workbench's site,
 * which cms:sites:sync printed; and then a login through the panel's form against the workbench
 * application composer workbench:serve serves, through its HTTP kernel, and a visit of the who-am-I
 * page, which shows the person's profile and the bootstrap role on the root node.
 *
 * The steps of dev:prepare that do not run Artisan are held elsewhere: the application key by
 * tests/Feature/Tooling/Workbench/WorkbenchToolsTest.php, the panel's build by the Browser suite,
 * which runs on it. The breach check and the hashing are bound for the test, and the panel's build is
 * a FixtureBuild, so the path runs in gate 5 before composer panel:build. Following the page in a
 * browser on the main checkout stays a manual step.
 */
final class GettingStartedPathTest extends TestCase
{
    use RealPostgres;
    use RealValkey;

    private const string NOW = '2026-03-10T12:00:00Z';

    private const string EMAIL = 'ada.lovelace@example.com';

    private const string NAME = 'Ada Lovelace';

    private const string PASSWORD = 'a long and quite unusual sentence';

    /** How a step of dev:prepare runs the workbench's Artisan in the dev image. */
    private const string ARTISAN = '@php tools/bin/dev-image.php -- php vendor/bin/testbench ';

    private ?FixtureBuild $build = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $clock = new FakeClock(new DateTimeImmutable(self::NOW));
        app()->instance(Clock::class, $clock);
        app()->instance(IdGenerator::class, new FakeIdGenerator(seed: 1919, clock: $clock));
        app()->instance(BreachedPasswords::class, new FakeBreachedPasswords);
        config(['cbox-cms.identity.passwords.argon2id' => ['memory_kib' => 1024, 'time' => 1]]);

        $this->build = FixtureBuild::write();
        $this->build->bind(app());
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->build?->remove();
        $this->build = null;

        parent::tearDown();
    }

    #[Test]
    public function dev_prepare_runs_twice_with_the_second_run_changing_nothing_and_the_first_member_of_staff_logs_in_to_the_who_am_i_page(): void
    {
        self::assertSame([
            'migrate --database=pgsql_owner --ansi',
            'cms:partitions:maintain --ansi',
            'cms:build --ansi',
            'cms:install --ansi',
            'cms:sites:sync --ansi',
        ], $this->artisanSteps());

        // composer dev:prepare, the first time on a migrated database, then again.
        $first = $this->devPrepare();
        $root = $this->rootNode($first['cms:sites:sync'], 'registered');
        $prepared = $this->state();
        $again = $this->devPrepare();

        self::assertSame($prepared, $this->state(), 'The second run of dev:prepare changed nothing.');
        self::assertStringContainsString('Nothing changed.', $again['cms:install']);
        self::assertSame($root, $this->rootNode($again['cms:sites:sync'], 'unchanged'));

        foreach (['table cms.installation', 'table cms.sites', 'table cms.migrations', 'table cms_identity.local_accounts'] as $key) {
            self::assertArrayHasKey($key, $prepared);
        }

        self::assertNotSame([], array_filter(array_keys($prepared), static fn (string $key): bool => str_starts_with($key, 'partition ')));
        self::assertNotSame([], array_filter(array_keys($prepared), static fn (string $key): bool => str_starts_with($key, 'registry ')));

        // cms:staff:create prints the id of the actor it registers: the next id of the generator.
        $clock = app(Clock::class);
        app()->instance(IdGenerator::class, new FakeIdGenerator(seed: 1920, clock: $clock));
        $actor = new FakeIdGenerator(seed: 1920, clock: $clock)->next()->value;
        $staff = $this->artisan('cms:staff:create', ['--email' => self::EMAIL, '--name' => self::NAME]);

        self::assertInstanceOf(PendingCommand::class, $staff);
        $staff->expectsQuestion('Password', self::PASSWORD)
            ->expectsQuestion('Repeat the password', self::PASSWORD)
            ->expectsOutput($actor)
            ->assertExitCode(0)
            ->run();

        $artisan = app(Kernel::class);
        $bootstrapped = $artisan->call('cms:access:bootstrap', ['actor' => $actor, 'node' => $root]);

        self::assertSame(0, $bootstrapped, $artisan->output());

        // The login through the panel's form, as the browser at http://127.0.0.1:8080/cms posts it.
        $this->get('/cms/login')->assertOk();
        $login = $this->from('/cms/login')->post('/cms/login', [
            LoginForm::EMAIL => self::EMAIL,
            LoginForm::PASSWORD => self::PASSWORD,
            '_token' => app('session.store')->token(),
        ]);
        $login->assertRedirect('/cms');
        $name = app(SessionCookie::class)->name;
        $cookie = $login->getCookie($name, false);

        self::assertInstanceOf(Cookie::class, $cookie, 'The login set no session cookie.');

        $me = $this->withUnencryptedCookie($name, (string) $cookie->getValue())
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => app(PanelBuild::class)->version])
            ->get('/cms/account/me')
            ->assertOk();
        $grants = array_map(
            static fn (mixed $grant): array => is_array($grant) ? [$grant['role_handle'] ?? null, $grant['node'] ?? null, $grant['effect'] ?? null] : [],
            (array) $me->json('props.result.grants'),
        );

        self::assertSame(PanelPages::ACCOUNT_ME, $me->json('component'));
        self::assertNull($me->json('props.rejection'));
        self::assertSame($actor, $me->json('props.result.actor'));
        self::assertSame('active', $me->json('props.result.state'));
        self::assertSame(['display_name' => self::NAME, 'email' => self::EMAIL], $me->json('props.result.profile'));
        self::assertSame([[config()->string('cbox-cms.access.bootstrap_role'), $root, 'allow']], $grants);
    }

    /**
     * The Artisan commands of composer dev:prepare's steps, in order.
     *
     * @return list<string>
     */
    private function artisanSteps(): array
    {
        $steps = [];

        foreach (ComposerScripts::steps('dev:prepare') as $step) {
            if (str_starts_with($step, self::ARTISAN)) {
                $steps[] = substr($step, strlen(self::ARTISAN));
            }
        }

        return $steps;
    }

    /**
     * Runs each Artisan step of dev:prepare and gives its output by command name; fails at the
     * first step that does not exit 0, as Composer stops there.
     *
     * @return array<string, string>
     */
    private function devPrepare(): array
    {
        $artisan = app(Kernel::class);
        $outputs = [];

        foreach ($this->artisanSteps() as $step) {
            $status = $artisan->call($step);
            $output = $artisan->output();

            self::assertSame(0, $status, "The step [{$step}] of composer dev:prepare exited {$status}:\n{$output}");

            $outputs[explode(' ', $step, 2)[0]] = $output;
        }

        return $outputs;
    }

    /**
     * What a run of dev:prepare could change: every table of the owner's search path, the
     * migration log and the credential store included, as its row count and a digest of its rows;
     * the partitions that exist; and the bytes of the registry cache.
     *
     * @return array<string, string>
     */
    private function state(): array
    {
        $owner = app(DatabaseManager::class)->connection('pgsql_owner');
        $state = [];

        foreach (new OwnerTruncation($owner, keep: [])->tables() as $table) {
            $quoted = implode('.', array_map(static fn (string $part): string => '"'.str_replace('"', '""', $part).'"', explode('.', $table, 2)));
            $row = (array) $owner->selectOne("select count(*)::text as rows, md5(coalesce(string_agg(t::text, E'\\n' order by t::text), '')) as digest from {$quoted} t");
            $rows = $row['rows'] ?? null;
            $digest = $row['digest'] ?? null;
            $state['table '.$table] = (is_string($rows) ? $rows : '').' '.(is_string($digest) ? $digest : '');
        }

        foreach ($owner->select('select c.oid::regclass::text as name from pg_inherits i join pg_class c on c.oid = i.inhrelid order by 1') as $partition) {
            $name = ((array) $partition)['name'] ?? null;
            $state['partition '.(is_string($name) ? $name : '')] = 'exists';
        }

        foreach (glob(app()->bootstrapPath('cache/cms').'/*') ?: [] as $file) {
            $state['registry '.basename($file)] = (string) hash_file('sha256', $file);
        }

        return $state;
    }

    /**
     * The root node a line of cms:sites:sync names for the workbench's site.
     */
    private function rootNode(string $output, string $outcome): string
    {
        $pattern = sprintf('/^%s %s: site [0-9a-f-]{36}, root node ([0-9a-f-]{36}), /m', $outcome, WorkbenchServiceProvider::SITE);

        self::assertMatchesRegularExpression($pattern, $output, "cms:sites:sync printed no {$outcome} line with the workbench's root node.");
        preg_match($pattern, $output, $match);

        return $match[1] ?? '';
    }
}
