<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon\Articles\Adapter;

use Cbox\Cms\Contracts\Ids\EntryId;
use Illuminate\Database\ConnectionResolverInterface;
use Override;
use Workbench\FixtureAddon\Articles\Domain\Dto\ArticleSummary;
use Workbench\FixtureAddon\Articles\Domain\FixtureArticles;

/**
 * FixtureArticles on Postgres: the type table of app:fixture_article, app__fixture_article, on the
 * default connection, inside the read transaction and under its actor context, so the policies of
 * the type table decide which rows the read sees (PRD 5.10). The table holds a row per entry,
 * locale and stage; the draft row exists only where a pending draft differs from what was
 * released, and it is read first, so an entry is listed once with its newest values.
 */
final readonly class PostgresFixtureArticles implements FixtureArticles
{
    public const string TABLE = 'app__fixture_article';

    public function __construct(private ConnectionResolverInterface $connections) {}

    #[Override]
    public function articles(int $limit): array
    {
        $rows = $this->connections->connection()
            ->table(self::TABLE)
            ->select(['cms_entry_id', 'cms_stage', 'fixture_title', 'ext__fixtureaddon__fixture_slug'])
            ->orderBy('cms_entry_id')
            ->orderBy('cms_stage')
            ->get();
        $articles = [];

        foreach ($rows as $row) {
            $entry = is_string($row->cms_entry_id) ? $row->cms_entry_id : '';

            if ($entry === '' || isset($articles[$entry])) {
                continue;
            }

            $articles[$entry] = new ArticleSummary(
                EntryId::fromString($entry),
                is_string($row->fixture_title) ? $row->fixture_title : '',
                is_string($row->ext__fixtureaddon__fixture_slug) ? $row->ext__fixtureaddon__fixture_slug : null,
            );

            if (count($articles) === $limit) {
                break;
            }
        }

        return array_values($articles);
    }

    #[Override]
    public function count(): int
    {
        return (int) $this->connections->connection()->table(self::TABLE)->distinct()->count('cms_entry_id');
    }
}
