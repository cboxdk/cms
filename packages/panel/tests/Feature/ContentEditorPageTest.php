<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Feature;

use Cbox\Cms\Contracts\Editors\TypeEditors;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Access\Domain\Dto\Grant;
use Cbox\Cms\Core\Access\Domain\HeldPermissions;
use Cbox\Cms\Core\Codecs\Boundary\Generated\EntryDetailCodecV1;
use Cbox\Cms\Core\Codecs\Boundary\Generated\TypeDescriptionCodecV1;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Schema\Domain\Dto\TypeDescription;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeAccessContexts;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeHeldPermissions;
use Cbox\Cms\Core\Tests\Access\Fakes\FakePermissions;
use Cbox\Cms\Core\Tests\Access\ListingWorld;
use Cbox\Cms\Core\Tests\Entries\EntryListingWorld;
use Cbox\Cms\Identity\Tests\Login\LocalLoginWorld;
use Cbox\Cms\Panel\Boundary\PanelPages;
use Cbox\Cms\Panel\Content\Domain\ContentEditor;
use Cbox\Cms\Panel\Contributions\Domain\CoreContributions;
use Cbox\Cms\Panel\Contributions\Domain\PointCodecs;
use Cbox\Cms\Panel\Domain\PanelRoute;
use Cbox\Cms\Panel\Pages\ContentEditorController;
use Cbox\Cms\Panel\Shell\Domain\OwnPage;
use Cbox\Cms\Panel\Tests\Access\AccessPagesWorld;
use Cbox\Cms\Panel\Tests\Contributions\ContributionWorld;
use Cbox\Cms\Panel\Tests\PanelLogins;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Testing\TestResponse;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The entry editor's page props over HTTP (PRD 5.4, 8.9, 11.12, 13.4), in the workbench, which mounts
 * the panel at /cms, over the fakes of AccessPagesWorld: a person who signed in gets, at
 * /cms/content/<type>/new, the page Content/Editor with the type the address names, no entry, the
 * address of the type's list, the read of type.describe, the type's editor descriptor as the
 * result codec writes it, and the read of node.list, the nodes the new entry's home may be; at
 * /cms/content/<type>/<entry> the same type and nodes reads, the read of entry.get of the entry as
 * the result codec writes it, with the address's entry in lower case, and the read of entry.preview,
 * the record of its draft, or of the revision the query parameter revision names; each read
 * through the QueryPipeline as the person from the session credential. A rejected read gives the
 * problem details in its place; an address of a type the installation does not have, or of an
 * entry that is no UUIDv7, is the page for a path the panel does not have; the page is never
 * stored; without a session the panel sends the browser to the login. The page reads entry.get,
 * type.describe, node.list and entry.preview, which QueryPageParity holds to REST, and has no
 * address of its own a link or a nav entry opens.
 */
final class ContentEditorPageTest extends TestCase
{
    use PanelLogins;

    private const string EMAIL = 'ada.admin@example.com';

    private const string ARTICLE = 'app:fixture_article';

    private ?AccessPagesWorld $world = null;

    private ?string $session = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $logins = $this->setUpPanelLogins();
        $person = $logins->person(self::EMAIL);
        $this->world = new AccessPagesWorld($logins->verifier(), $person->id, $logins->clock, $logins->identity);
        $registry = ContributionWorld::registry(core: CoreContributions::all());

        app()->instance(CompiledRegistry::class, $registry);
        app()->instance(RegistryCache::class, ContributionWorld::cache($registry));
        app()->instance(PointCodecs::class, ContributionWorld::pointCodecs());
        app()->instance(HeldPermissions::class, new FakeHeldPermissions(
            new FakePermissions([])->grant(
                $person->id,
                new Grant(RoleId::fromString(ListingWorld::ADMIN_ROLE), ClassificationAccess::Personal, new NodePath('a1'), GrantEffect::Allow),
                array_map(static fn (string $name): CommandName => new CommandName($name), AccessPagesWorld::ADMINISTRATION),
            ),
            new FakeAccessContexts()->grant($person->id, ClassificationAccess::Personal),
        ));
        app()->instance(QueryPipeline::class, $this->world->pipeline());
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->tearDownPanelLogins();
        $this->world = null;
        $this->session = null;

        parent::tearDown();
    }

    #[Test]
    public function the_page_of_a_new_entry_carries_the_type_and_the_nodes_its_home_may_be_and_no_entry(): void
    {
        $response = $this->visitSignedIn('/cms/content/'.self::ARTICLE.'/new');
        $page = $this->page($response);
        $props = $this->propsOf($page);

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        self::assertSame(PanelPages::CONTENT_EDITOR, $page['component'] ?? null);
        self::assertSame('/cms/logout', $props['logout'] ?? null);
        self::assertSame(self::ARTICLE, $props['type'] ?? null);
        self::assertSame('/cms/content/'.self::ARTICLE, $props['list'] ?? null);
        self::assertArrayHasKey('entry', $props);
        self::assertNull($props['entry']);
        self::assertArrayHasKey('entry_read', $props);
        self::assertNull($props['entry_read']);
        self::assertSame(['rejection' => null, 'result' => $this->typeDocument()], $props['type_read'] ?? null);
        self::assertNull($this->at($props, 'nodes_read', 'rejection'));
        self::assertSame(json_decode(AccessPagesWorld::documents()['node.list'], true, 32, JSON_THROW_ON_ERROR), $this->at($props, 'nodes_read', 'result'));
        self::assertArrayHasKey('preview_read', $props);
        self::assertNull($props['preview_read']);
        self::assertArrayHasKey('revision', $props);
        self::assertNull($props['revision']);
    }

    #[Test]
    public function the_page_of_an_entry_carries_the_entry_as_entry_get_reads_it_the_nodes_and_the_preview_of_its_draft(): void
    {
        $props = $this->propsOf($this->page($this->visitSignedIn('/cms/content/'.self::ARTICLE.'/'.strtoupper(EntryListingWorld::FIRST))->assertOk()));
        $entry = json_decode(new EntryDetailCodecV1()->encode(EntryListingWorld::detail(EntryListingWorld::FIRST), ClassificationAccess::Personal), true, 32, JSON_THROW_ON_ERROR);

        self::assertSame(EntryListingWorld::FIRST, $props['entry'] ?? null);
        self::assertSame(['rejection' => null, 'result' => $entry], $props['entry_read'] ?? null);
        self::assertSame(['rejection' => null, 'result' => $this->typeDocument()], $props['type_read'] ?? null);
        self::assertSame(json_decode(AccessPagesWorld::documents()['node.list'], true, 32, JSON_THROW_ON_ERROR), $this->at($props, 'nodes_read', 'result'));
        self::assertSame('First', $this->at($props, 'entry_read', 'result', 'heads', 0, 'fields', EntryListingWorld::TITLE));
        self::assertSame(1, $this->at($props, 'entry_read', 'result', 'heads', 0, 'version'));
        self::assertArrayHasKey('revision', $props);
        self::assertNull($props['revision']);
        self::assertNull($this->at($props, 'preview_read', 'rejection'));
        self::assertSame(1, $this->at($props, 'preview_read', 'result', 'revision'));
        self::assertSame('app:fixture_article', $this->at($props, 'preview_read', 'result', 'type'));
        self::assertSame('First', $this->at($props, 'preview_read', 'result', 'data', EntryListingWorld::TITLE));
        self::assertSame(EntryListingWorld::FIRST, $this->at($props, 'preview_read', 'result', 'data', 'cms_id'));
    }

    #[Test]
    public function the_page_previews_the_revision_its_address_names_and_the_draft_for_a_parameter_that_is_no_revision(): void
    {
        $released = $this->propsOf($this->page($this->visitSignedIn('/cms/content/'.self::ARTICLE.'/'.EntryListingWorld::SECOND.'?revision=2')->assertOk()));
        $missing = $this->propsOf($this->page($this->visitSignedIn('/cms/content/'.self::ARTICLE.'/'.EntryListingWorld::SECOND.'?revision=9')->assertOk()));

        self::assertSame(2, $released['revision'] ?? null);
        self::assertSame('Second, released', $this->at($released, 'preview_read', 'result', 'data', EntryListingWorld::TITLE));
        self::assertSame('revision_not_found', $this->at($missing, 'preview_read', 'rejection', 'code'));

        foreach (['0', 'two', '-1', '1e3'] as $parameter) {
            $draft = $this->propsOf($this->page($this->visitSignedIn('/cms/content/'.self::ARTICLE.'/'.EntryListingWorld::SECOND.'?revision='.$parameter)->assertOk()));

            self::assertArrayHasKey('revision', $draft, $parameter);
            self::assertNull($draft['revision'], $parameter);
            self::assertSame('Second', $this->at($draft, 'preview_read', 'result', 'data', EntryListingWorld::TITLE), $parameter);
        }
    }

    #[Test]
    public function an_entry_the_person_cannot_read_carries_the_problem_details_of_entry_get(): void
    {
        $props = $this->propsOf($this->page($this->visitSignedIn('/cms/content/'.self::ARTICLE.'/'.EntryListingWorld::UNKNOWN)->assertOk()));

        self::assertNull($this->at($props, 'entry_read', 'result'));
        self::assertSame('entry_not_found', $this->at($props, 'entry_read', 'rejection', 'code'));
    }

    #[Test]
    public function a_rejected_read_carries_the_problem_details_in_place_of_the_result(): void
    {
        $world = $this->world ?? self::fail('No world.');
        app()->instance(QueryPipeline::class, $world->refusing());

        $props = $this->propsOf($this->page($this->visitSignedIn('/cms/content/'.self::ARTICLE.'/'.EntryListingWorld::FIRST)->assertOk()));

        self::assertNull($this->at($props, 'type_read', 'result'));
        self::assertSame('unauthorized', $this->at($props, 'type_read', 'rejection', 'code'));
        self::assertSame('unauthorized', $this->at($props, 'entry_read', 'rejection', 'code'));
        self::assertSame('unauthorized', $this->at($props, 'preview_read', 'rejection', 'code'));
    }

    #[Test]
    public function an_address_of_a_type_the_installation_does_not_have_or_of_no_entry_id_is_the_page_for_a_missing_path(): void
    {
        $unknownType = $this->page($this->visitSignedIn('/cms/content/app:no_such_type/new')->assertNotFound());
        $noUuid7 = $this->page($this->visitSignedIn('/cms/content/'.self::ARTICLE.'/0192a0c0-0000-4000-8000-0000000004e1')->assertNotFound());

        self::assertSame(PanelPages::NOT_FOUND, $unknownType['component'] ?? null);
        self::assertSame(PanelPages::NOT_FOUND, $noUuid7['component'] ?? null);
        self::assertNull(app(TypeEditors::class)->named(new TypeName('app:no_such_type')));
    }

    #[Test]
    public function it_sends_a_browser_without_a_session_to_the_login(): void
    {
        $this->get('/cms/content/'.self::ARTICLE.'/new')->assertStatus(303)->assertRedirect('/cms/login?reason=required');
        $this->get('/cms/content/'.self::ARTICLE.'/'.EntryListingWorld::FIRST)->assertStatus(303)->assertRedirect('/cms/login?reason=required');
    }

    #[Test]
    public function the_page_is_served_by_its_controller_reads_its_four_queries_and_has_no_address_of_its_own(): void
    {
        $router = app(Router::class)->getRoutes();
        $new = $router->getByName(PanelRoute::ContentEditorNew->value);
        $entry = $router->getByName(OwnPage::ContentEditor->route()->value);

        self::assertInstanceOf(Route::class, $new);
        self::assertInstanceOf(Route::class, $entry);
        self::assertSame('cms/content/{type}/new', $new->uri());
        self::assertSame('cms/content/{type}/{entry}', $entry->uri());
        self::assertSame(ContentEditorController::class, $new->getActionName());
        self::assertSame(ContentEditorController::class, $entry->getActionName());
        self::assertSame(ContentEditor::PAGE, OwnPage::ContentEditor->value);
        self::assertSame(['entry.get@1', 'type.describe@1', 'node.list@1', 'entry.preview@1'], array_map(static fn (CommandRef $query): string => $query->toString(), OwnPage::ContentEditor->queries()));
        self::assertFalse(OwnPage::ContentEditor->linkable());
        self::assertTrue(OwnPage::ContentList->linkable());
    }

    /**
     * The editor descriptor of the fixture article as type.describe's result codec writes it.
     *
     * @return array<array-key, mixed>
     */
    private function typeDocument(): array
    {
        $document = json_decode(new TypeDescriptionCodecV1()->encode(new TypeDescription(app(TypeEditors::class)->named(new TypeName(self::ARTICLE))), ClassificationAccess::Personal), true, 64, JSON_THROW_ON_ERROR);

        return is_array($document) ? $document : self::fail('No descriptor.');
    }

    /**
     * Visits the path with the session, signing in at the first visit of the test.
     *
     * @return TestResponse<Response>
     */
    private function visitSignedIn(string $path): TestResponse
    {
        if ($this->session === null) {
            $this->visitLogin();
            $this->session = $this->sessionCookie($this->logIn(self::EMAIL, LocalLoginWorld::PASSWORD))?->getValue() ?? self::fail('No session.');
        }

        return $this->withUnencryptedCookie($this->cookieName(), $this->session)->get($path);
    }

    /**
     * The Inertia page the response rendered, with its props as the browser receives them.
     *
     * @param  TestResponse<Response>  $response
     * @return array<array-key, mixed>
     */
    private function page(TestResponse $response): array
    {
        $page = $response->viewData('page');

        if (! is_array($page)) {
            self::fail('The response rendered no Inertia page.');
        }

        $page['props'] = json_decode(json_encode($page['props'] ?? [], JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);

        return $page;
    }

    /**
     * @param  array<array-key, mixed>  $page
     * @return array<array-key, mixed>
     */
    private function propsOf(array $page): array
    {
        $props = $page['props'] ?? null;

        return is_array($props) ? $props : [];
    }

    /**
     * The value at the keys of a decoded document, or null where there is none.
     *
     * @param  array<array-key, mixed>  $document
     */
    private function at(array $document, string|int ...$keys): mixed
    {
        $value = $document;

        foreach ($keys as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }

        return $value;
    }
}
