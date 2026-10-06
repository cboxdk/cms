<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Feature;

use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Reads\Domain\UnknownQuery;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Queries\ListActions;
use Cbox\Cms\Core\Tests\Access\ListingActionWorld;
use Cbox\Cms\Core\Tests\Registry\ActionListWorld;
use Cbox\Cms\Identity\Tests\Login\LocalLoginWorld;
use Cbox\Cms\Panel\Palette\Boundary\PaletteProps;
use Cbox\Cms\Panel\Tests\PanelLogins;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The prop `palette` over HTTP (GUARDRAILS 8, PRD 13.4), in the workbench, which mounts the panel
 * at /cms: a person who signed in gets the prop on the start page with the result of action.list
 * read as the person, here over a registry with no action, so an empty list; and the palette never
 * blanks the page: when the pipeline cannot make the read, because its registry knows no
 * action.list, the page still answers 200 with the prop carrying neither result nor rejection, and
 * the failure is reported to the application's exception handler. A logout, which redirects, reads
 * nothing.
 */
final class PalettePropTest extends TestCase
{
    use PanelLogins;

    private const string EMAIL = 'liv.kaas@example.com';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPanelLogins()->person(self::EMAIL);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->tearDownPanelLogins();

        parent::tearDown();
    }

    #[Test]
    public function it_gives_the_start_page_the_result_of_action_list_read_as_the_person(): void
    {
        $palette = $this->palette($this->visitSignedIn('/cms'));

        self::assertSame(['actions' => [], 'navigation' => []], $palette['result'] ?? null);
        self::assertArrayHasKey('rejection', $palette);
        self::assertNull($palette['rejection']);
    }

    #[Test]
    public function it_leaves_the_prop_without_result_or_rejection_and_reports_the_failure_when_the_pipeline_cannot_make_the_read(): void
    {
        Exceptions::fake();
        app()->instance(QueryPipeline::class, new ListingActionWorld()->pipeline());

        $palette = $this->palette($this->visitSignedIn('/cms'));

        self::assertSame(['rejection' => null, 'result' => null], $palette);
        Exceptions::assertReported(UnknownQuery::class);
    }

    #[Test]
    public function the_prop_is_the_read_of_action_list_at_its_version(): void
    {
        self::assertSame('palette', PaletteProps::PROP);
        self::assertSame('action.list@1', PaletteProps::query()->toString());
        $list = new ActionListWorld(CompiledRegistry::empty())->action()->handle(new ListActions);

        self::assertSame([], $list->actions);
        self::assertSame([], $list->navigation);
    }

    /**
     * @return TestResponse<Response>
     */
    private function visitSignedIn(string $path): TestResponse
    {
        $this->visitLogin();
        $session = $this->sessionCookie($this->logIn(self::EMAIL, LocalLoginWorld::PASSWORD))?->getValue() ?? self::fail('No session.');

        return $this->withUnencryptedCookie($this->cookieName(), $session)->get($path)->assertOk();
    }

    /**
     * The prop `palette` of the Inertia page the response rendered, decoded as the browser receives it.
     *
     * @param  TestResponse<Response>  $response
     * @return array<array-key, mixed>
     */
    private function palette(TestResponse $response): array
    {
        $page = $response->viewData('page');
        $props = is_array($page) ? json_decode(json_encode($page['props'] ?? [], JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR) : null;
        $palette = is_array($props) ? $props[PaletteProps::PROP] ?? null : null;

        self::assertIsArray($palette, 'The page carries the prop palette.');

        return $palette;
    }
}
