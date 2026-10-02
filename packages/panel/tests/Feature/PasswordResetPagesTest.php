<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Feature;

use Cbox\Cms\Identity\PasswordReset\Actions\RequestPasswordReset;
use Cbox\Cms\Identity\Tests\Login\LocalLoginWorld;
use Cbox\Cms\Identity\Tests\PasswordReset\PasswordResetWorld;
use Cbox\Cms\Identity\Tests\Sessions\SessionWorld;
use Cbox\Cms\Panel\Boundary\PanelSessions;
use Cbox\Cms\Panel\Boundary\PasswordResetForms;
use Cbox\Cms\Panel\Tests\FixtureBuild;
use Cbox\Cms\Panel\Tests\PanelLogins;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Testing\TestResponse;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The panel's password reset pages over HTTP (PRD 5.16, GUARDRAILS 6), over PasswordResetWorld's
 * fakes: the page that asks for a link answers every email the same, mails a link only for a known
 * active account and refuses a post without the CSRF token; the page a link opens is never cached,
 * sends no Referer and shows no form for a text that is not a token; its form sets the password,
 * ends the person's sessions and starts a new one, goes back with the catalog code of a refusal,
 * and sends the browser to the login page when the login policy wants another kind of login.
 */
final class PasswordResetPagesTest extends TestCase
{
    use PanelLogins;

    private const string EMAIL = 'mette.holm@example.com';

    private ?PasswordResetWorld $resets = null;

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
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->tearDownPanelLogins();
        $this->resets = null;

        parent::tearDown();
    }

    #[Test]
    public function the_login_page_links_to_the_page_that_asks_for_a_link(): void
    {
        $this->get('/cms/login')->assertOk();

        self::assertSame('/cms/forgot-password', $this->props($this->get('/cms/login'))['forgot'] ?? null);
    }

    #[Test]
    public function every_email_gets_the_same_answer_and_only_a_known_active_account_gets_a_mail(): void
    {
        $this->get('/cms/forgot-password')->assertOk();

        foreach (['Mette.Holm@example.com', 'nobody@example.com'] as $email) {
            $this->from('/cms/forgot-password')->post('/cms/forgot-password', [PasswordResetForms::EMAIL => $email, '_token' => $this->csrf()])
                ->assertStatus(PanelSessions::REDIRECT)
                ->assertHeader('Location', '/cms/forgot-password')
                ->assertSessionHas(PasswordResetForms::REQUESTED, true)
                ->assertSessionHasNoErrors();

            self::assertSame(['action' => '/cms/forgot-password', 'login' => '/cms/login', 'requested' => true, 'minutes' => 60], $this->props($this->get('/cms/forgot-password')));
        }

        $world = $this->world();

        self::assertCount(1, $world->mail->sent());
        self::assertSame(self::EMAIL, $world->mail->sent()[0]->to->value);
        self::assertSame(2, $world->login->telemetry->counted(RequestPasswordReset::REQUESTS));
        self::assertFalse($this->props($this->get('/cms/forgot-password'))['requested'] ?? null);
    }

    #[Test]
    public function an_empty_email_goes_back_with_validation_required_and_a_post_without_the_csrf_token_is_refused(): void
    {
        $this->get('/cms/forgot-password')->assertOk();

        $this->post('/cms/forgot-password', [PasswordResetForms::EMAIL => self::EMAIL])->assertStatus(419);
        $this->post('/cms/forgot-password', [PasswordResetForms::EMAIL => '', '_token' => $this->csrf()])
            ->assertSessionHasErrors([PasswordResetForms::EMAIL => 'validation_required'])
            ->assertSessionMissing(PasswordResetForms::REQUESTED);

        self::assertSame([], $this->world()->mail->sent());
    }

    #[Test]
    public function the_reset_page_is_never_cached_sends_no_referer_and_knows_a_text_that_is_no_token(): void
    {
        $token = $this->mailedToken();

        $page = $this->get('/cms/reset-password/'.$token)
            ->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer');

        self::assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));
        self::assertSame(['action' => '/cms/reset-password', 'token' => $token, 'forgot' => '/cms/forgot-password', 'login' => '/cms/login'], $this->props($page));
        $invalid = $this->props($this->get('/cms/reset-password/not-a-token'));

        self::assertArrayHasKey('token', $invalid);
        self::assertNull($invalid['token']);
    }

    #[Test]
    public function a_new_password_ends_the_sessions_of_the_person_and_starts_a_new_one(): void
    {
        $world = $this->world();
        $this->get('/cms/login');
        $old = $this->sessionCookie($this->logIn(self::EMAIL, LocalLoginWorld::PASSWORD))?->getValue() ?? self::fail('No session.');
        $token = $this->mailedToken();
        $this->withUnencryptedCookie($this->cookieName(), $old);

        $response = $this->from('/cms/reset-password/'.$token)->post('/cms/reset-password', [
            PasswordResetForms::TOKEN => $token,
            PasswordResetForms::PASSWORD => PasswordResetWorld::NEW_PASSWORD,
            '_token' => $this->csrf(),
        ]);
        $new = $this->sessionCookie($response)?->getValue();

        $response->assertStatus(PanelSessions::REDIRECT)->assertHeader('Location', '/cms');
        self::assertNotNull($new);
        self::assertNotSame($old, $new);
        self::assertSame(1, $world->login->sessions->count());
        $this->withUnencryptedCookie($this->cookieName(), $old)->get('/cms')->assertRedirect('/cms/login?reason=ended');
        $this->withUnencryptedCookie($this->cookieName(), $new)->get('/cms')->assertOk();
    }

    #[Test]
    public function a_refusal_goes_back_to_the_reset_page_with_its_catalog_code(): void
    {
        $token = $this->mailedToken();

        $this->post('/cms/reset-password', [PasswordResetForms::TOKEN => $token, PasswordResetForms::PASSWORD => 'too short', '_token' => $this->csrf()])
            ->assertStatus(PanelSessions::REDIRECT)
            ->assertHeader('Location', '/cms/reset-password/'.$token)
            ->assertSessionHasErrors([PasswordResetForms::PASSWORD => 'password_too_short']);
        $this->post('/cms/reset-password', [PasswordResetForms::TOKEN => 'cms_pr_'.str_repeat('0', 72), PasswordResetForms::PASSWORD => PasswordResetWorld::NEW_PASSWORD, '_token' => $this->csrf()])
            ->assertHeader('Location', '/cms/reset-password/cms_pr_'.str_repeat('0', 72))
            ->assertSessionHasErrors([PasswordResetForms::FORM => 'password_reset_token_invalid']);
        $this->post('/cms/reset-password', [PasswordResetForms::TOKEN => '../../login?x=1', PasswordResetForms::PASSWORD => PasswordResetWorld::NEW_PASSWORD, '_token' => $this->csrf()])
            ->assertHeader('Location', '/cms/reset-password/'.PasswordResetForms::NO_TOKEN)
            ->assertSessionHasErrors([PasswordResetForms::FORM => 'password_reset_token_invalid']);
        $this->post('/cms/reset-password', [PasswordResetForms::TOKEN => $token, PasswordResetForms::PASSWORD => PasswordResetWorld::NEW_PASSWORD])->assertStatus(419);

        self::assertSame(0, $this->world()->login->sessions->count());
    }

    #[Test]
    public function a_reset_the_login_policy_does_not_log_in_sends_the_browser_to_the_login_page(): void
    {
        $world = $this->world();
        $world->login->policy = SessionWorld::policy(['staff' => ['methods' => ['password_reset' => false]]]);
        $world->into($this->app ?? self::fail('No application.'));
        $token = $this->mailedToken();

        $this->post('/cms/reset-password', [PasswordResetForms::TOKEN => $token, PasswordResetForms::PASSWORD => PasswordResetWorld::NEW_PASSWORD, '_token' => $this->csrf()])
            ->assertStatus(PanelSessions::REDIRECT)
            ->assertHeader('Location', '/cms/login?reason=password_changed');

        self::assertSame(0, $world->login->sessions->count());
    }

    private function mailedToken(): string
    {
        $this->get('/cms/forgot-password');
        $this->post('/cms/forgot-password', [PasswordResetForms::EMAIL => self::EMAIL, '_token' => $this->csrf()]);

        return $this->world()->mailedToken();
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array<array-key, mixed>
     */
    private function props(TestResponse $response): array
    {
        $page = $response->viewData('page');
        $props = is_array($page) ? $page['props'] ?? null : null;

        if (! is_array($props)) {
            self::fail('The response rendered no Inertia page.');
        }

        unset($props['errors'], $props['problem']);

        return $props;
    }

    private function world(): PasswordResetWorld
    {
        return $this->resets ?? self::fail('No world.');
    }
}
