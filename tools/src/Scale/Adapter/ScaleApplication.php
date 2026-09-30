<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Scale\Adapter;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\TypeTables\SortDirection;
use Cbox\Cms\Contracts\TypeTables\TypeTableCursor;
use Cbox\Cms\Core\Access\Domain\AccessContexts;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Cbox\Cms\Testkit\FixtureWriters\Access\Adapter\PostgresAccessFixtures;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Adapter\PostgresStructureFixtures;
use Cbox\Cms\Tooling\Scale\Boundary\ExplainJson;
use Cbox\Cms\Tooling\Scale\Domain\ListingTimes;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\Foundation\Application as Testbench;
use Orchestra\Testbench\Foundation\Config;
use UnexpectedValueException;
use Workbench\App\Cms\Generated\QueryBuilders\AppFixtureArticle\AppFixtureArticleQuery;
use Workbench\App\Cms\Generated\QueryBuilders\AppFixtureArticle\AppFixtureArticleSortField;

/**
 * The workbench application on the scale database, in the scale check's own process: it writes the
 * structure and the service actor the seed runs as with the testkit's fixture writers, because node
 * and grant commands come with later blocks, runs ANALYZE, and measures the workbench's listing,
 * the generated query builder's newest-first keyset page of 20 of the workbench's article type,
 * with EXPLAIN (ANALYZE) as the actor.
 */
final readonly class ScaleApplication
{
    /** The handle of the site the scale check seeds below. */
    public const string SITE = 'scale';

    /** The handle of the service actor's role. */
    public const string ROLE = 'scale_seeder';

    /** A page of the listing. */
    public const int PAGE = 20;

    private function __construct(private Application $app) {}

    /**
     * Boots the workbench application from testbench.yaml with the database given.
     */
    public static function boot(string $root, string $database): self
    {
        foreach (['DB_DATABASE' => $database] as $name => $value) {
            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        return new self(Testbench::createFromConfig(Config::loadFromYaml($root), options: ['enables_package_discoveries' => true]));
    }

    /**
     * The service actor that seeds and lists: the one granted on the root of the scale site when
     * the site exists, else a new service actor, the site with its sections, and a role on the
     * site's root that allows seed.entries up to internal fields.
     */
    public function world(int $sections): ActorId
    {
        $owner = $this->owner();
        $site = $owner->table('sites')->where('handle', self::SITE)->value('root_node_id');

        if (is_string($site)) {
            $actor = $owner->table('grants')->where('node_id', $site)->whereNull('ended_changeset_id')->orderBy('actor_id')->value('actor_id');

            return is_string($actor) ? ActorId::fromString($actor) : throw new UnexpectedValueException(sprintf('The scale site "%s" has no grant on its root; remove the scale database and run again.', self::SITE));
        }

        $connections = $this->app->make(ConnectionResolverInterface::class);
        $clock = $this->app->make(Clock::class);
        $ids = $this->app->make(IdGenerator::class);
        $actor = new PostgresIdentitySeeder($connections, $clock, $ids)->addActor(ActorClass::Service)->id;
        $structure = new PostgresStructureFixtures($connections, $clock, $ids);
        $root = $structure->site(self::SITE, [new Locale('en')])->root;

        for ($section = 0; $section < $sections; $section++) {
            $structure->node($root);
        }

        $access = new PostgresAccessFixtures($this->app->make(DatabaseManager::class), $clock, $ids);
        $access->grant($actor, $access->role(self::ROLE, ClassificationAccess::Internal, [new CommandName('seed.entries')]), $root->id);

        return $actor;
    }

    /**
     * ANALYZE of the whole database as the owner role, so the planner has statistics.
     */
    public function analyze(): void
    {
        $this->owner()->statement('analyze');
    }

    /**
     * The first page and the page after it, each run $runs times with EXPLAIN (ANALYZE): the sum
     * of the execution times of the page's statements per run.
     *
     * @return array{ListingTimes, ListingTimes, int} the two pages and the rows of the first
     */
    public function listing(ActorId $actor, int $runs): array
    {
        $access = $this->app->make(AccessContexts::class)->for(new ActorPrincipal($actor, [], IssuerKind::Service, IssuerKind::Service->maximumCeiling()));
        $db = $this->app->make(ConnectionResolverInterface::class)->connection();
        $db->beginTransaction();

        $statements = [];
        $this->app->make('events')->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = [$query->sql, $query->bindings];
        });

        try {
            new ActorContext($this->app->make(ConnectionResolverInterface::class))->set($access);
            $query = $this->app->make(AppFixtureArticleQuery::class)
                ->orderBy(AppFixtureArticleSortField::FixturePublishedOn, SortDirection::Descending)
                ->limit(self::PAGE);
            $start = count($statements);
            $page = $query->page($access);
            $first = array_slice($statements, $start);
            $start = count($statements);
            $next = $page->next instanceof TypeTableCursor ? $query->after($page->next)->page($access) : null;
            $second = array_slice($statements, $start);

            return [
                new ListingTimes('newest first, first page of '.self::PAGE, $this->explain($db, $first, $runs)),
                new ListingTimes('newest first, keyset page after it', $this->explain($db, $next === null ? $first : $second, $runs)),
                count($page->records),
            ];
        } finally {
            $db->rollBack();
        }
    }

    /**
     * @param  list<array{string, array<array-key, mixed>}>  $statements
     * @return non-empty-list<float>
     */
    private function explain(ConnectionInterface $db, array $statements, int $runs): array
    {
        $times = [];

        for ($run = 0; $run < max(1, $runs); $run++) {
            $sum = 0.0;

            foreach ($statements as [$sql, $bindings]) {
                $row = $db->selectOne('explain (analyze, format json) '.$sql, $db->prepareBindings($bindings));
                $plan = is_object($row) ? (get_object_vars($row)['QUERY PLAN'] ?? null) : null;
                $sum += ExplainJson::executionMilliseconds(is_string($plan) ? $plan : throw new UnexpectedValueException('EXPLAIN returned no plan.'));
            }

            $times[] = $sum;
        }

        return $times;
    }

    private function owner(): ConnectionInterface
    {
        return $this->app->make(ConnectionResolverInterface::class)->connection('pgsql_owner');
    }
}
