<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Feature;

use Cbox\Cms\Identity\Login\Actions\LogInLocally;
use Cbox\Cms\Identity\Tests\Login\LocalLoginWorld;
use Cbox\Cms\Panel\Boundary\LoginForm;
use Cbox\Cms\Panel\Boundary\PanelSessions;
use Cbox\Cms\Panel\Tests\PanelLogins;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Override;
use PHPUnit\Framework\Attributes\Test;

/**
 * The login form's rate limit over HTTP (PRD 5.16, GUARDRAILS 6): five failed logins for one email
 * address in the window each go back to the login page with the one generic refusal,
 * login_rejected, whether the email is unknown or the password wrong; the sixth is refused with
 * login_rate_limited, even with the right password, and counted in cms.login.rate_limited. Empty
 * fields are validation_required on the field and are not counted.
 */
final class LoginRateLimitTest extends TestCase
{
    use PanelLogins;

    private const string EMAIL = 'mette.holm@example.com';

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
    public function the_sixth_failed_login_for_one_identifier_within_the_window_is_refused_with_login_rate_limited(): void
    {
        $this->visitLogin();

        foreach (range(1, 5) as $attempt) {
            $this->logIn(self::EMAIL, 'not the password '.$attempt)
                ->assertStatus(PanelSessions::REDIRECT)
                ->assertHeader('Location', '/cms/login')
                ->assertSessionHasErrors([LoginForm::FORM => 'login_rejected']);

            self::assertNull($this->sessionCookie($this->get('/cms/login')), 'attempt '.$attempt);
        }

        $this->logIn(self::EMAIL, LocalLoginWorld::PASSWORD)
            ->assertStatus(PanelSessions::REDIRECT)
            ->assertHeader('Location', '/cms/login')
            ->assertSessionHasErrors([LoginForm::FORM => 'login_rate_limited']);

        $logins = $this->logins ?? self::fail('No logins.');

        self::assertSame(0, $logins->sessions->count());
        self::assertSame(1, $logins->telemetry->counted(LogInLocally::RATE_LIMITED));
        self::assertCount(5, $logins->hasher->verified, 'The sixth login checks no password.');

        $this->logIn('Mette.Holm@Example.com ', LocalLoginWorld::PASSWORD)->assertSessionHasErrors([LoginForm::FORM => 'login_rate_limited']);
        $this->logIn('someone.else@example.com', LocalLoginWorld::PASSWORD)->assertSessionHasErrors([LoginForm::FORM => 'login_rejected']);
    }

    #[Test]
    public function an_unknown_email_and_a_wrong_password_get_the_same_answer(): void
    {
        $this->visitLogin();

        $unknown = $this->logIn('nobody@example.com', LocalLoginWorld::PASSWORD);
        $wrong = $this->logIn(self::EMAIL, 'not the password');

        foreach ([$unknown, $wrong] as $response) {
            $response->assertStatus(PanelSessions::REDIRECT)
                ->assertHeader('Location', '/cms/login')
                ->assertSessionHasErrors([LoginForm::FORM => 'login_rejected'])
                ->assertSessionDoesntHaveErrors([LoginForm::EMAIL, LoginForm::PASSWORD]);
        }
    }

    #[Test]
    public function empty_fields_are_validation_required_on_the_field_and_not_counted(): void
    {
        $this->visitLogin();

        foreach (range(1, 6) as $attempt) {
            $this->logIn('', '')->assertSessionHasErrors([LoginForm::EMAIL => 'validation_required', LoginForm::PASSWORD => 'validation_required']);
        }

        $this->logIn(self::EMAIL, LocalLoginWorld::PASSWORD)
            ->assertStatus(PanelSessions::REDIRECT)
            ->assertHeader('Location', '/cms')
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function a_login_without_the_csrf_token_is_refused(): void
    {
        $this->visitLogin();

        $this->post('/cms/login', [LoginForm::EMAIL => self::EMAIL, LoginForm::PASSWORD => LocalLoginWorld::PASSWORD])->assertStatus(419);

        self::assertSame(0, ($this->logins ?? self::fail('No logins.'))->sessions->count());
    }

    #[Test]
    public function the_panel_checks_the_csrf_token_whatever_the_application_excludes_from_its_own_check(): void
    {
        $this->visitLogin();
        PreventRequestForgery::except(['cms/*']);

        try {
            $this->post('/cms/login', [LoginForm::EMAIL => self::EMAIL, LoginForm::PASSWORD => LocalLoginWorld::PASSWORD])->assertStatus(419);
        } finally {
            PreventRequestForgery::flushState();
        }

        self::assertSame(0, ($this->logins ?? self::fail('No logins.'))->sessions->count());
    }
}
