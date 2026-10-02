<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Unit;

use Cbox\Cms\Contracts\Doctor\CheckStatus;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Core\Tests\Process\ProcessEnvironment;
use Cbox\Cms\Identity\Doctor\Domain\Checks\SessionCookieCheck;
use Cbox\Cms\Identity\Doctor\Domain\Probes\SessionCookieProbe;
use Cbox\Cms\Identity\IdentityServiceProvider;
use Cbox\Cms\Identity\Sessions\Boundary\SessionCookieConfig;
use Cbox\Cms\Identity\Sessions\Domain\Dto\SessionCookie;
use Cbox\Cms\Identity\Sessions\Domain\InsecureSessionCookie;
use Cbox\Cms\Identity\Sessions\Domain\InvalidSessionCookie;
use Cbox\Cms\Identity\Sessions\Domain\SameSite;
use Cbox\Cms\Identity\Tests\Doctor\Fakes\FakeSessionCookieProbe;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;

/*
 * The session cookie (PRD 5.16) per environment, from cbox-cms.identity.session.cookie: in
 * production __Host-cms_session, Secure, HttpOnly and SameSite=Lax; in local and testing
 * cms_session, HttpOnly and SameSite=Lax without Secure, because the workbench and the browser
 * tests serve plain HTTP on 127.0.0.1. A process that serves HTTP refuses to boot with a cookie
 * that is invalid, or not safe outside local and testing, and identity.session_cookie says why.
 */

/**
 * The module's session cookie setting with $changes merged over it, as an application's
 * configuration would be.
 *
 * @param  array<array-key, mixed>  $changes
 */
function cookieConfig(array $changes = []): Repository
{
    /** @var array<string, mixed> $module */
    $module = require __DIR__.'/../../config/identity.php';

    return new Repository(['cbox-cms' => ['identity' => array_replace_recursive($module, ['session' => ['cookie' => $changes]])]]);
}

/**
 * Boots an application with the identity module's provider in the given environment, as a process
 * that serves HTTP unless $server says otherwise, and puts the test's container back afterwards.
 *
 * @param  array<array-key, mixed>  $server
 */
function bootIdentity(string $environment, Repository $config, array $server = ['APP_RUNNING_IN_CONSOLE' => 'false']): Application
{
    $current = Container::getInstance();
    $basePath = app()->basePath();

    try {
        return ProcessEnvironment::during(
            array_merge(['APP_RUNNING_IN_CONSOLE' => null, 'LARAVEL_OCTANE' => null, 'argv' => ['vendor/bin/pest']], $server),
            static function () use ($environment, $config, $basePath): Application {
                $app = new Application($basePath);
                $app->instance('config', $config);
                $app['env'] = $environment;
                $app->register(IdentityServiceProvider::class);
                $app->boot();

                return $app;
            },
        );
    } finally {
        Container::setInstance($current);
    }
}

/**
 * @return array{CheckStatus, FailureKind|null, string|null}
 */
function cookieVerdict(SessionCookieProbe $probe): array
{
    $result = new SessionCookieCheck($probe)->run();

    return [$result->status, $result->failure, $result->code];
}

it('sets __Host-cms_session, Secure, HttpOnly and SameSite=Lax in production and every environment without an entry', function (string $environment): void {
    $cookie = SessionCookieConfig::read(cookieConfig(), $environment);

    expect($cookie->name)->toBe('__Host-cms_session')
        ->and($cookie->secure)->toBeTrue()
        ->and(SessionCookie::HTTP_ONLY)->toBeTrue()
        ->and(SessionCookie::PATH)->toBe('/')
        ->and($cookie->sameSite)->toBe(SameSite::Lax)
        ->and($cookie->sameSite->attribute())->toBe('Lax')
        ->and($cookie->insecurities())->toBe([])
        ->and($cookie->safeIn($environment))->toBeTrue();
})->with(['production', 'staging']);

it('sets cms_session, HttpOnly and SameSite=Lax without Secure in local and testing', function (string $environment): void {
    $cookie = SessionCookieConfig::read(cookieConfig(), $environment);

    expect($cookie->name)->toBe('cms_session')
        ->and($cookie->secure)->toBeFalse()
        ->and($cookie->sameSite)->toBe(SameSite::Lax)
        ->and($cookie->safeIn($environment))->toBeTrue()
        ->and($cookie->safeIn('production'))->toBeFalse()
        ->and($cookie->insecurities())->toHaveCount(2);
})->with(['local', 'testing']);

it('binds the cookie of the environment the tests run in', function (): void {
    expect(app()->environment())->toBe('testing')
        ->and(app(SessionCookie::class)->name)->toBe('cms_session')
        ->and(app(SessionCookie::class)->secure)->toBeFalse();
});

it('refuses a setting it cannot use and names the key', function (array $changes, string $message): void {
    expect(static fn (): SessionCookie => SessionCookieConfig::read(cookieConfig($changes), 'production'))
        ->toThrow(InvalidSessionCookie::class, '[session_cookie_invalid] cbox-cms.identity.session.cookie.'.$message);
})->with([
    'a name with a space' => [['production' => ['name' => 'cms session']], 'production.name must be a cookie name of 1 to 128 visible ASCII characters without separators.'],
    'a __Host- name without Secure' => [['production' => ['secure' => false]], 'production.secure must be true for a name with the __Host- prefix, which a browser accepts only as a Secure cookie.'],
    'SameSite=None without Secure' => [['production' => ['name' => 'cms_session', 'secure' => false, 'same_site' => 'none']], 'production.same_site must be lax or strict for a cookie that is not Secure, because a browser refuses SameSite=None without Secure.'],
    'an unknown same_site' => [['production' => ['same_site' => 'loose']], 'production.same_site must be lax, strict or none.'],
    'secure as a string' => [['production' => ['secure' => 'yes']], 'production.secure must be true or false.'],
    'an unknown key' => [['production' => ['domain' => 'example.test']], 'production.domain must be left out; an entry has only name, secure and same_site.'],
]);

it('refuses to boot a process that serves HTTP in production with an insecure cookie', function (array $changes, string $reason): void {
    expect(static fn (): Application => bootIdentity('production', cookieConfig(['production' => $changes])))->toThrow(
        InsecureSessionCookie::class,
        sprintf('[session_cookie_insecure] The session cookie of the environment production is not safe outside local and testing: %s. Set cbox-cms.identity.session.cookie.production to the name __Host-cms_session with secure true and same_site lax or strict, or remove the entry so the default applies; cms:doctor\'s identity.session_cookie says the same.', $reason),
    );
})->with([
    'the development cookie' => [['name' => 'cms_session', 'secure' => false], 'it is not Secure, so a browser sends it over plain HTTP; its name lacks the __Host- prefix, so another host of the domain could set it'],
    'Secure without the prefix' => [['name' => 'cms_session'], 'its name lacks the __Host- prefix, so another host of the domain could set it'],
    'SameSite=None' => [['same_site' => 'none'], 'it is SameSite=None, so a browser sends it with requests other sites start'],
]);

it('refuses to boot a process that serves HTTP with a setting it cannot use', function (): void {
    expect(static fn (): Application => bootIdentity('production', cookieConfig(['production' => ['same_site' => 'loose']])))
        ->toThrow(InvalidSessionCookie::class);
});

it('boots a process that serves HTTP with a safe cookie, and local or testing with the development cookie', function (string $environment): void {
    expect(bootIdentity($environment, cookieConfig())->make(SessionCookie::class)->name)
        ->toBe(SessionCookie::isDevelopment($environment) ? 'cms_session' : '__Host-cms_session');
})->with(['production', 'staging', 'local', 'testing']);

it('boots a console process with an insecure cookie, so cms:doctor can say why', function (): void {
    $insecure = cookieConfig(['production' => ['name' => 'cms_session', 'secure' => false]]);

    expect(bootIdentity('production', $insecure, ['argv' => ['artisan', 'cms:doctor']]))->toBeInstanceOf(Application::class);
});

it('passes identity.session_cookie for a safe cookie and fails it with the reason otherwise', function (): void {
    $insecure = new SessionCookie(SessionCookie::DEVELOPMENT_NAME, false, SameSite::Lax);
    $failure = new SessionCookieCheck(new FakeSessionCookieProbe(cookie: $insecure))->run();

    expect(cookieVerdict(new FakeSessionCookieProbe))->toBe([CheckStatus::Pass, null, null])
        ->and(cookieVerdict(new FakeSessionCookieProbe('local', $insecure)))->toBe([CheckStatus::Pass, null, null])
        ->and(cookieVerdict(new FakeSessionCookieProbe(cookie: $insecure)))->toBe([CheckStatus::Fail, FailureKind::Violation, SessionCookieCheck::CODE_INSECURE])
        ->and($failure->cause)->toBe('The cookie cms_session: it is not Secure, so a browser sends it over plain HTTP; its name lacks the __Host- prefix, so another host of the domain could set it.')
        ->and(cookieVerdict(new FakeSessionCookieProbe(invalid: InvalidSessionCookie::key('production.secure', 'true or false'))))->toBe([CheckStatus::Fail, FailureKind::Violation, SessionCookieCheck::CODE_INVALID])
        ->and(new SessionCookieCheck(new FakeSessionCookieProbe)->blocking())->toBeTrue()
        ->and(array_map(ErrorCode::tryFrom(...), [SessionCookieCheck::CODE_INSECURE, SessionCookieCheck::CODE_INVALID, InsecureSessionCookie::CODE, InvalidSessionCookie::CODE]))->not->toContain(null);
});

it('reads the cookie of the application for identity.session_cookie', function (): void {
    expect(cookieVerdict(app(SessionCookieProbe::class)))->toBe([CheckStatus::Pass, null, null]);
});
