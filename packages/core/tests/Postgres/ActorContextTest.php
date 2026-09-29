<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Consistency\TransactionRequired;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Core\Access\Adapter\PostgresAccessResolver;
use Cbox\Cms\Core\Access\Domain\AccessCompiler;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/*
 * The actor context on Postgres (PRD 5.10): ActorContext sets it with SET LOCAL inside the
 * caller's transaction and nowhere else, the settings end with the transaction, and a second set
 * replaces the first. The access resolver needs the transaction too.
 */

/**
 * The settings the policies read, as the connection sees them now.
 *
 * @return list<string>
 */
function contextSettings(Connection $connection): array
{
    return StorageTables::texts($connection, <<<'SQL'
        select concat_ws(' | ',
            coalesce(cms_access_context(), '-'),
            coalesce(cms_access_actor()::text, '-'),
            coalesce(nullif(current_setting('cbox_cms.access_allowed', true), ''), '-'),
            coalesce(nullif(current_setting('cbox_cms.access_denied', true), ''), '-'),
            coalesce(nullif(current_setting('cbox_cms.classification', true), ''), '-'),
            current_setting('plan_cache_mode')
        ) as value
        SQL);
}

it('refuses to set the context outside a transaction, and sets nothing', function (): void {
    $app = DB::connection();

    expect(fn () => new ActorContext(app('db'))->set(AccessContext::anonymous()))->toThrow(TransactionRequired::class, 'SET LOCAL inside the transaction')
        ->and(fn (): AccessContext => new PostgresAccessResolver(app('db'), new AccessCompiler)->resolve(AccessWorld::alice()))->toThrow(TransactionRequired::class)
        ->and(contextSettings($app))->toBe(['- | - | - | - | - | auto']);
});

it('sets the principal, the regions, their exceptions and the classification for the transaction, with custom plans, and ends them with it', function (): void {
    $app = DB::connection();
    $context = new AccessContext(AccessWorld::alice(), [
        new AccessRegion(new NodePath('root.news'), [new NodePath('root.news.sport'), new NodePath('root.news.weather')]),
        new AccessRegion(new NodePath('root.news.sport.football')),
        new AccessRegion(new NodePath('root.culture')),
    ], ClassificationAccess::Internal);

    $app->beginTransaction();
    new ActorContext(app('db'))->set($context);
    $set = contextSettings($app);
    $reaches = StorageTables::texts($app, <<<'SQL'
        select string_agg(p || '=' || cms_access_reaches(p::ltree)::text, ' ' order by ord) as value
        from unnest(array['root', 'root.news', 'root.news.local', 'root.news.sport', 'root.news.sport.golf', 'root.news.sport.football', 'root.news.sport.football.league', 'root.news.weather', 'root.culture.music', 'root.newsroom']) with ordinality as t (p, ord)
        SQL);
    $classes = StorageTables::texts($app, "select string_agg(c || '=' || cms_access_classification_allows(c)::text, ' ' order by ord) as value from unnest(array['public', 'internal', 'confidential', 'personal', 'sensitive', 'secret']) with ordinality as t (c, ord)");
    $app->commit();

    expect($set)->toBe([sprintf('actor | %s | {root.news,root.news.sport.football,root.culture} | {root.news.sport,root.news.weather} | internal | force_custom_plan', AccessWorld::ALICE)])
        ->and($reaches)->toBe(['root=false root.news=true root.news.local=true root.news.sport=false root.news.sport.golf=false root.news.sport.football=true root.news.sport.football.league=true root.news.weather=false root.culture.music=true root.newsroom=false'])
        ->and($classes)->toBe(['public=true internal=true confidential=false personal=false sensitive=false secret=false'])
        ->and(contextSettings($app))->toBe(['- | - | - | - | - | auto']);
});

it('replaces the whole context when it is set again, and gives the anonymous context no actor and no regions', function (): void {
    $app = DB::connection();
    $actorContext = new ActorContext(app('db'));

    $app->beginTransaction();
    $actorContext->set(new AccessContext(AccessWorld::bob(), [new AccessRegion(new NodePath('root.sport'))], ClassificationAccess::Sensitive));
    $actorContext->set(AccessContext::anonymous());
    $anonymous = contextSettings($app);
    $reaches = $app->scalar("select cms_access_reaches('root.sport'::ltree)");
    $app->rollBack();

    expect($anonymous)->toBe(['anonymous | - | {} | {} | public | force_custom_plan'])
        ->and($reaches)->toBeFalse()
        ->and(contextSettings($app))->toBe(['- | - | - | - | - | auto']);
});

it('reads no context from settings the functions do not know', function (): void {
    $app = DB::connection();

    $app->beginTransaction();
    $app->statement("select set_config('cbox_cms.principal', 'admin', true), set_config('cbox_cms.actor', ?, true), set_config('cbox_cms.access_allowed', '{root}', true), set_config('cbox_cms.classification', 'sensitive', true)", [AccessWorld::ALICE]);
    $unknown = contextSettings($app);
    $reaches = $app->scalar("select cms_access_reaches('root'::ltree)");
    $allows = $app->scalar("select cms_access_classification_allows('public')");
    $app->rollBack();

    expect($unknown)->toBe(['- | - | {root} | - | sensitive | auto'])
        ->and($reaches)->toBeFalse()
        ->and($allows)->toBeFalse();
});
