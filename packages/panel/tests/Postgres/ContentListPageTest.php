<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Postgres;

use Cbox\Cms\Core\Tests\Entries\ListedContent;
use Cbox\Cms\Panel\Boundary\PanelPages;
use Cbox\Cms\Panel\Content\Domain\ContentList;
use Cbox\Cms\Panel\Content\Domain\ContentTypes;
use Cbox\Cms\Panel\Tests\Access\AccessWorld;
use Cbox\Cms\Panel\Tests\Content\ContentWorld;
use Cbox\Cms\Panel\Tests\PanelSignIn;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Testing\TestResponse;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The content list in the panel (PRD 5.4, 13.4), on real Postgres and Valkey as the app role over
 * ContentWorld, signed in through the panel's login form: /cms/content renders the page
 * Content/List with the newest entries the administrator reaches across every type, each with the
 * title field its type's editor descriptor names, a page of ContentList::LIMIT at a time with the
 * next page after the id the address names; /cms/content/<owner>:<handle> lists one type and
 * `?state=` filters by the shared variant's release state, as the page's props say; a type the
 * installation does not have is the page for a path the panel does not have; and every page
 * behind the login shares the prop `types`, the read of type.list, with the address of the list,
 * from which the navigation's Content section is built. The list's result equals what REST answers
 * the same viewer with for the same query.
 */
final class ContentListPageTest extends TestCase
{
    use PanelSignIn;
    use RealPostgres;

    private ?ContentWorld $world = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $world = new ContentWorld($this->app ?? self::fail('No application.'));
        $this->world = $world;
        $this->giveLocalAccount($world->access->admin, AccessWorld::ADMIN_EMAIL);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->world?->cleanUp();
        $this->world = null;

        parent::tearDown();
    }

    #[Test]
    public function it_lists_the_newest_entries_across_every_type_with_their_titles_a_page_at_a_time(): void
    {
        $session = $this->signInAs(AccessWorld::ADMIN_EMAIL);
        $first = $this->visitPanel($session, '/cms/content')->assertOk();
        $entries = $this->entries($first);

        self::assertSame(PanelPages::CONTENT_LIST, $first->json('component'));
        self::assertSame(PanelPages::PRIVATE_CACHE_CONTROL, $first->headers->get('Cache-Control'));
        self::assertNull($first->json('props.type'));
        self::assertNull($first->json('props.state'));
        self::assertNull($first->json('props.rejection'));
        self::assertCount(ContentList::LIMIT, $entries);
        self::assertSame(ListedContent::id(ListedContent::ARTICLES), $entries[0]['id'] ?? null);
        self::assertSame(['fixture_title' => ListedContent::title(ListedContent::ARTICLES)], $entries[0]['fields'] ?? null);
        self::assertSame('released', $entries[0]['state'] ?? null);
        self::assertSame('unreleased', $entries[ListedContent::RELEASED]['state'] ?? null);

        $next = ListedContent::id(ListedContent::ARTICLES - ContentList::LIMIT + 1);
        self::assertSame($next, $first->json('props.result.next'));

        $second = $this->entries($this->visitPanel($session, '/cms/content?after='.$next)->assertOk());

        self::assertSame([ListedContent::id(2), ListedContent::id(1), ListedContent::id(0)], array_column($second, 'id'));
        self::assertSame(['fixture_name' => ListedContent::EVENT_NAME], $second[2]['fields'] ?? null);
    }

    #[Test]
    public function it_lists_one_type_and_filters_by_the_release_state_the_address_names(): void
    {
        $session = $this->signInAs(AccessWorld::ADMIN_EMAIL);
        $events = $this->visitPanel($session, '/cms/content/'.ListedContent::EVENT)->assertOk();
        $released = $this->visitPanel($session, '/cms/content/'.ListedContent::ARTICLE.'?state=released')->assertOk();
        $empty = $this->visitPanel($session, '/cms/content/'.ListedContent::MEASUREMENT)->assertOk();
        $unknownState = $this->visitPanel($session, '/cms/content?state=live')->assertOk();

        self::assertSame(ListedContent::EVENT, $events->json('props.type'));
        self::assertSame([ListedContent::id(0)], array_column($this->entries($events), 'id'));
        self::assertSame('released', $released->json('props.state'));
        self::assertSame([ListedContent::id(ListedContent::ARTICLES), ListedContent::id(ListedContent::ARTICLES - 1)], array_column($this->entries($released), 'id'));
        self::assertSame([], $this->entries($empty));
        self::assertNull($empty->json('props.result.next'));
        self::assertNull($unknownState->json('props.state'));
        self::assertCount(ContentList::LIMIT, $this->entries($unknownState));
    }

    #[Test]
    public function a_type_the_installation_does_not_have_is_the_page_for_a_path_the_panel_does_not_have(): void
    {
        $session = $this->signInAs(AccessWorld::ADMIN_EMAIL);

        $this->visitPanel($session, '/cms/content/app:no_such_type')->assertNotFound()->assertJsonPath('component', PanelPages::NOT_FOUND);
    }

    #[Test]
    public function every_page_behind_the_login_shares_the_types_with_the_address_of_the_list(): void
    {
        $session = $this->signInAs(AccessWorld::ADMIN_EMAIL);

        foreach (['/cms', '/cms/content', '/cms/access/roles'] as $path) {
            $page = $this->visitPanel($session, $path)->assertOk();
            $types = $page->json('props.'.ContentTypes::PROP.'.result.types');

            self::assertSame('/cms/content', $page->json('props.'.ContentTypes::PROP.'.content'), $path);
            self::assertNull($page->json('props.'.ContentTypes::PROP.'.rejection'), $path);
            self::assertSame(
                ['app:fixture_article', 'app:fixture_event', 'app:fixture_measurement', 'app:fixture_notice'],
                array_column(is_array($types) ? $types : [], 'name'),
                $path,
            );
        }
    }

    #[Test]
    public function the_list_s_result_equals_what_rest_answers_the_same_viewer(): void
    {
        $world = $this->world ?? self::fail('No world.');
        $bearer = ['Authorization' => 'Bearer '.$world->access->serviceCredential->reveal()];
        $rest = $this->withHeaders($bearer)->get('/v1/queries/entry.list/v1?query='.rawurlencode('{"limit":'.ContentList::LIMIT.'}'))->assertOk();
        $session = $this->signInAs(AccessWorld::ADMIN_EMAIL);
        $page = $this->visitPanel($session, '/cms/content')->assertOk();

        self::assertSame(
            json_decode((string) $rest->getContent(), true, 64, JSON_THROW_ON_ERROR),
            json_decode(json_encode($page->json('props.result'), JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * The entries of the page's result.
     *
     * @param  TestResponse<Response>  $page
     * @return list<array<array-key, mixed>>
     */
    private function entries(TestResponse $page): array
    {
        $entries = $page->json('props.result.entries');

        return is_array($entries) ? array_values(array_filter($entries, is_array(...))) : [];
    }
}
