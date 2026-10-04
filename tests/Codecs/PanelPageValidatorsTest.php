<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Codecs;

use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Core\Access\Domain\Dto\Grant;
use Cbox\Cms\Core\Access\Domain\HeldPermissions;
use Cbox\Cms\Core\Codecs\Boundary\Generated\KernelQueryCodecs;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeAccessContexts;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeHeldPermissions;
use Cbox\Cms\Core\Tests\Access\Fakes\FakePermissions;
use Cbox\Cms\Identity\Tests\Login\LocalLoginWorld;
use Cbox\Cms\Identity\Tests\PasswordReset\PasswordResetWorld;
use Cbox\Cms\Panel\Boundary\Generated\AccountMePageCodecV1;
use Cbox\Cms\Panel\Boundary\Generated\AddonPageCodecV1;
use Cbox\Cms\Panel\Boundary\Generated\ForgotPasswordPageCodecV1;
use Cbox\Cms\Panel\Boundary\Generated\HomePageCodecV1;
use Cbox\Cms\Panel\Boundary\Generated\LoginPageCodecV1;
use Cbox\Cms\Panel\Boundary\Generated\NotFoundPageCodecV1;
use Cbox\Cms\Panel\Boundary\Generated\PanelBrandCodecV1;
use Cbox\Cms\Panel\Boundary\Generated\ResetPasswordPageCodecV1;
use Cbox\Cms\Panel\Boundary\PanelBrandProps;
use Cbox\Cms\Panel\Boundary\PanelPages;
use Cbox\Cms\Panel\Boundary\PasswordResetForms;
use Cbox\Cms\Panel\Branding\Boundary\BrandingConfig;
use Cbox\Cms\Panel\Branding\Domain\Dto\Branding;
use Cbox\Cms\Panel\Contributions\Boundary\ContributionProps;
use Cbox\Cms\Panel\Contributions\Domain\PointCodecs;
use Cbox\Cms\Panel\Tests\Account\AccountMeWorld;
use Cbox\Cms\Panel\Tests\Branding\BrandFixtures;
use Cbox\Cms\Panel\Tests\Contributions\ContributionWorld;
use Cbox\Cms\Panel\Tests\Contributions\Fixtures\Tally\TallyCodecs;
use Cbox\Cms\Panel\Tests\FixtureBuild;
use Cbox\Cms\Panel\Tests\PanelLogins;
use Cbox\Cms\Tests\Support\TypeScript\TypeScriptValidators;
use Cbox\Cms\Tests\TestCase;
use Cbox\Cms\Tooling\Protocol\Domain\PanelPageSchemas;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Testing\TestResponse;
use JsonException;
use Override;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * The TypeScript of the panel's page props, run in Node against what the panel actually renders
 * (GUARDRAILS 2.2, 2.4 and 9): every page of PanelPages, in each state it has, is requested over
 * HTTP in the workbench, and the props of the Inertia page it rendered, as the browser receives
 * them and without the props every page shares, must pass the validator generated from the page's
 * JSON Schema into js/panel/src/generated, which the page in js/panel imports, and read back
 * through the page's PHP codec to the same JSON. A value planted wrong, such as a refusal code the
 * schema does not list, makes the validator refuse it, so a prop or a code that PHP adds without the
 * schema fails here instead of reaching a page that does not know it.
 */
final class PanelPageValidatorsTest extends TestCase
{
    use PanelLogins;

    private const string EMAIL = 'mette.holm@example.com';

    /** A second account, which logs in for the start page after the first is rate limited. */
    private const string SECOND_EMAIL = 'jonas.berg@example.com';

    /**
     * The generated module and validator of each page's component.
     *
     * @var array<string, array{module: string, validator: string}>
     */
    private const array PAGES = [
        PanelPages::LOGIN => ['module' => 'pages/LoginPageV1', 'validator' => 'validateLoginPageV1'],
        PanelPages::FORGOT_PASSWORD => ['module' => 'pages/ForgotPasswordPageV1', 'validator' => 'validateForgotPasswordPageV1'],
        PanelPages::RESET_PASSWORD => ['module' => 'pages/ResetPasswordPageV1', 'validator' => 'validateResetPasswordPageV1'],
        PanelPages::HOME => ['module' => 'pages/HomePageV1', 'validator' => 'validateHomePageV1'],
        PanelPages::NOT_FOUND => ['module' => 'pages/NotFoundPageV1', 'validator' => 'validateNotFoundPageV1'],
        PanelPages::ADDON => ['module' => 'pages/AddonPageV1', 'validator' => 'validateAddonPageV1'],
        PanelPages::ACCOUNT_ME => ['module' => 'pages/AccountMePageV1', 'validator' => 'validateAccountMePageV1'],
    ];

    private ?PasswordResetWorld $resets = null;

    private ?AccountMeWorld $account = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $app = $this->app ?? self::fail('No application.');
        $this->fixture = FixtureBuild::write();
        $this->fixture->bind($app);
        $this->resets = new PasswordResetWorld;
        $this->resets->into($app);
        $this->logins = $this->resets->login;
        $this->logins->person(self::EMAIL);
        $second = $this->logins->person(self::SECOND_EMAIL);

        // The who-am-I page's read of actor.me as the second person, over fakes (AccountMeWorld).
        $this->account = new AccountMeWorld($this->logins->verifier(), $second->id, self::SECOND_EMAIL);
        $app->instance(QueryPipeline::class, $this->account->pipeline());

        // The test addon's page at /cms/x/tally/board, which the second person may open: the
        // registry ContributionWorld compiles, the points' codecs, and the permissions over fakes.
        $registry = ContributionWorld::registry();
        $app->instance(CompiledRegistry::class, $registry);
        $app->instance(RegistryCache::class, ContributionWorld::cache($registry));
        $app->instance(PointCodecs::class, ContributionWorld::pointCodecs());
        $app->instance(QueryCodecs::class, new QueryCodecs(...TallyCodecs::all(), ...KernelQueryCodecs::all()));
        $app->instance(HeldPermissions::class, new FakeHeldPermissions(
            new FakePermissions([])->grant(
                $second->id,
                new Grant(RoleId::fromString('0192a0c0-0000-7000-8000-000000000b11'), ClassificationAccess::Confidential, new NodePath('a1'), GrantEffect::Allow),
                [new CommandName(ContributionWorld::BOARD_PERMISSION)],
            ),
            new FakeAccessContexts()->grant($second->id, ClassificationAccess::Confidential),
        ));
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->tearDownPanelLogins();
        $this->resets = null;
        $this->account = null;

        parent::tearDown();
    }

    /**
     * @throws JsonException
     */
    #[Test]
    public function the_typescript_validators_accept_the_props_of_every_page_the_panel_renders_in_every_state(): void
    {
        $rendered = $this->renderedPages();
        $components = array_unique(array_map(static fn (array $page): string => $page['component'], $rendered));
        sort($components);
        $expected = array_keys(self::PAGES);
        sort($expected);

        self::assertSame($expected, $components, 'Every page of PanelPages is rendered.');

        $verdicts = TypeScriptValidators::run(PanelPageSchemas::TYPESCRIPT_DIRECTORY, array_map(
            static fn (array $page): array => ['module' => self::PAGES[$page['component']]['module'], 'validator' => self::PAGES[$page['component']]['validator'], 'document' => $page['props']],
            $rendered,
        ));

        foreach ($rendered as $index => $page) {
            self::assertSame(['valid' => true], $verdicts[$index], sprintf('%s %s: %s', $page['state'], $page['component'], $page['props']));

            self::assertSame(
                json_decode($page['props'], true, 16, JSON_THROW_ON_ERROR),
                json_decode($this->readBack($page['component'], $page['props']), true, 16, JSON_THROW_ON_ERROR),
                sprintf('%s %s reads back through its PHP codec.', $page['state'], $page['component']),
            );
        }

        $states = array_map(static fn (array $page): string => $page['state'], $rendered);

        self::assertContains('login refused with login_rate_limited', $states);
        self::assertContains('who am I with the read refused', $states);
    }

    /**
     * The brand every page shares (brand.v1.json), without branding and with a logo: the generated
     * validator accepts what each page renders, and it reads back through PanelBrandCodecV1.
     *
     * @throws JsonException
     */
    #[Test]
    public function the_typescript_validator_accepts_the_brand_every_page_shares(): void
    {
        $plain = $this->brand($this->get('/cms/login'));
        $files = new BrandFixtures;

        try {
            app(Repository::class)->set(BrandingConfig::KEY, ['root' => $files->root, 'name' => 'Skovbo Content', 'logo' => ['light' => 'brand/logo.svg', 'dark' => 'brand/logo-dark.svg', 'alt' => 'Skovbo']]);
            app()->forgetInstance(Branding::class);
            $branded = $this->brand($this->get('/cms/login'));
        } finally {
            $files->remove();
        }

        $verdicts = TypeScriptValidators::run(PanelPageSchemas::TYPESCRIPT_DIRECTORY, array_map(
            static fn (string $document): array => ['module' => 'pages/PanelBrandV1', 'validator' => 'validatePanelBrandV1', 'document' => $document],
            [$plain, $branded],
        ));

        self::assertSame('{"login":null,"logo":null,"name":"Cbox CMS"}', $plain);
        self::assertStringContainsString('"name":"Skovbo Content"', $branded);
        self::assertSame([['valid' => true], ['valid' => true]], $verdicts);

        foreach ([$plain, $branded] as $document) {
            self::assertSame($document, $this->through(new PanelBrandCodecV1, $document));
        }
    }

    /**
     * @throws JsonException
     */
    #[Test]
    public function the_typescript_validators_refuse_props_planted_wrong(): void
    {
        $login = $this->props($this->from('/cms/login')->get('/cms/login'));
        $reset = $this->props($this->get('/cms/reset-password/invalid'));
        $cases = [
            ['login', 'refusals.form', $this->plant($login, static function (stdClass $props): void {
                $refusals = $props->refusals;
                self::assertInstanceOf(stdClass::class, $refusals);
                $refusals->form = 'login_unknown';
            })],
            ['login', 'reason', $this->plant($login, static function (stdClass $props): void {
                $props->reason = 'timed_out';
            })],
            ['login', 'refusals', $this->plant($login, static function (stdClass $props): void {
                unset($props->refusals);
            })],
            ['reset', 'refusals.password', $this->plant($reset, static function (stdClass $props): void {
                $refusals = $props->refusals;
                self::assertInstanceOf(stdClass::class, $refusals);
                $refusals->password = 'login_rejected';
            })],
            ['reset', null, $this->plant($reset, static function (stdClass $props): void {
                $props->extra = true;
            })],
        ];

        $verdicts = TypeScriptValidators::run(PanelPageSchemas::TYPESCRIPT_DIRECTORY, array_map(
            static fn (array $case): array => [
                'module' => self::PAGES[$case[0] === 'login' ? PanelPages::LOGIN : PanelPages::RESET_PASSWORD]['module'],
                'validator' => self::PAGES[$case[0] === 'login' ? PanelPages::LOGIN : PanelPages::RESET_PASSWORD]['validator'],
                'document' => $case[2],
            ],
            $cases,
        ));

        foreach ($cases as $index => [$page, $path]) {
            $planted = sprintf('%s with %s planted wrong', $page, $path ?? 'a key the schema does not have');

            self::assertFalse($verdicts[$index]['valid'], $planted);
            self::assertSame($path, $verdicts[$index]['path'] ?? null, $planted);
        }
    }

    /**
     * Each page of the panel in each of its states, as the workbench renders it: the state, the
     * Inertia component and the JSON of its own props.
     *
     * @return list<array{state: string, component: string, props: string}>
     *
     * @throws JsonException
     */
    private function renderedPages(): array
    {
        $pages = [];
        $page = function (string $state, TestResponse $response) use (&$pages): void {
            $pages[] = ['state' => $state, 'component' => $this->inertiaComponent($response), 'props' => $this->props($response)];
        };

        $page('login', $this->visitLogin());
        $page('login after an expired session', $this->get('/cms/login?reason=expired'));
        $page('login with an unknown reason', $this->get('/cms/login?reason=nonsense'));
        $this->logIn('', '');
        $page('login refused with validation_required', $this->get('/cms/login'));

        foreach (range(1, 5) as $attempt) {
            $this->logIn(self::EMAIL, 'not the password '.$attempt);
        }

        $page('login refused with login_rejected', $this->get('/cms/login'));
        $this->logIn(self::EMAIL, LocalLoginWorld::PASSWORD);
        $page('login refused with login_rate_limited', $this->get('/cms/login'));

        $page('forgot password', $this->get('/cms/forgot-password'));
        $this->post('/cms/forgot-password', [PasswordResetForms::EMAIL => '', '_token' => $this->csrf()]);
        $page('forgot password refused with validation_required', $this->get('/cms/forgot-password'));
        $this->post('/cms/forgot-password', [PasswordResetForms::EMAIL => self::EMAIL, '_token' => $this->csrf()]);
        $page('forgot password requested', $this->get('/cms/forgot-password'));

        $token = $this->world()->mailedToken();
        $page('reset password', $this->get('/cms/reset-password/'.$token));
        $page('reset password without a token', $this->get('/cms/reset-password/'.PasswordResetForms::NO_TOKEN));
        $this->post('/cms/reset-password', [PasswordResetForms::TOKEN => $token, PasswordResetForms::PASSWORD => 'too short', '_token' => $this->csrf()]);
        $page('reset password refused with password_too_short', $this->get('/cms/reset-password/'.$token));
        $this->post('/cms/reset-password', [PasswordResetForms::TOKEN => 'cms_pr_'.str_repeat('0', 72), PasswordResetForms::PASSWORD => PasswordResetWorld::NEW_PASSWORD, '_token' => $this->csrf()]);
        $page('reset password refused with password_reset_token_invalid', $this->get('/cms/reset-password/'.$token));
        $session = $this->sessionCookie($this->logIn(self::SECOND_EMAIL, LocalLoginWorld::PASSWORD))?->getValue() ?? self::fail('No session.');

        $page('home', $this->withUnencryptedCookie($this->cookieName(), $session)->get('/cms'));
        $page('addon page', $this->withUnencryptedCookie($this->cookieName(), $session)->get('/cms/x/tally/'.ContributionWorld::BOARD_PATH));
        $page('who am I', $this->withUnencryptedCookie($this->cookieName(), $session)->get('/cms/account/me'));
        app()->instance(QueryPipeline::class, ($this->account ?? self::fail('No account world.'))->refusing());
        $page('who am I with the read refused', $this->withUnencryptedCookie($this->cookieName(), $session)->get('/cms/account/me'));
        $page('not found', $this->get('/cms/no-such-page'));

        return $pages;
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function inertiaComponent(TestResponse $response): string
    {
        $page = $response->viewData('page');
        $component = is_array($page) ? $page['component'] ?? null : null;

        return is_string($component) ? $component : self::fail('The response rendered no Inertia page.');
    }

    /**
     * The JSON of the page's own props, as the browser receives them, without the props every page
     * shares, Inertia's errors, the problem and the brand, which brand() reads, the
     * contributions every page behind the login sends (ContributionProps::CMS, held to its own
     * validator by ContributionsCodecTest) and the deferred data of the addons (ContributionProps::DATA).
     *
     * @param  TestResponse<Response>  $response
     *
     * @throws JsonException
     */
    private function props(TestResponse $response): string
    {
        $page = $response->viewData('page');
        $props = is_array($page) ? $page['props'] ?? null : null;

        if (! is_array($props)) {
            self::fail('The response rendered no Inertia page.');
        }

        unset($props['errors'], $props['problem'], $props[PanelBrandProps::PROP], $props[ContributionProps::CMS], $props[ContributionProps::DATA]);

        return json_encode((object) $props, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The JSON of the brand the page shares, as the browser receives it.
     *
     * @param  TestResponse<Response>  $response
     *
     * @throws JsonException
     */
    private function brand(TestResponse $response): string
    {
        $page = $response->viewData('page');
        $brand = is_array($page) && is_array($page['props'] ?? null) ? $page['props'][PanelBrandProps::PROP] ?? null : null;

        if (! is_array($brand)) {
            self::fail('The page shares no brand.');
        }

        return json_encode((object) $brand, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The props with one value planted wrong.
     *
     * @param  callable(stdClass): void  $plant
     *
     * @throws JsonException
     */
    private function plant(string $props, callable $plant): string
    {
        $value = json_decode($props, false, 16, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $value);
        $plant($value);

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The props read through the PHP codec of the page's component and written again.
     */
    private function readBack(string $component, string $props): string
    {
        return match ($component) {
            PanelPages::LOGIN => $this->through(new LoginPageCodecV1, $props),
            PanelPages::FORGOT_PASSWORD => $this->through(new ForgotPasswordPageCodecV1, $props),
            PanelPages::RESET_PASSWORD => $this->through(new ResetPasswordPageCodecV1, $props),
            PanelPages::HOME => $this->through(new HomePageCodecV1, $props),
            PanelPages::NOT_FOUND => $this->through(new NotFoundPageCodecV1, $props),
            PanelPages::ADDON => $this->through(new AddonPageCodecV1, $props),
            PanelPages::ACCOUNT_ME => $this->through(new AccountMePageCodecV1, $props),
            default => self::fail('No codec for the page '.$component.'.'),
        };
    }

    /**
     * @template TDto of object
     *
     * @param  JsonCodec<TDto>  $codec
     */
    private function through(JsonCodec $codec, string $json): string
    {
        return $codec->encode($codec->decode($json, ClassificationAccess::Public), ClassificationAccess::Public);
    }

    private function world(): PasswordResetWorld
    {
        return $this->resets ?? self::fail('No world.');
    }
}
