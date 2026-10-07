<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\PasswordResetToken;
use Cbox\Cms\Http\Inertia\Boundary\InertiaProps;
use Cbox\Cms\Identity\PasswordReset\Domain\Dto\ResetSettings;
use Cbox\Cms\Panel\Boundary\Generated\AccountMePageCodecV1;
use Cbox\Cms\Panel\Boundary\Generated\AddonPageCodecV1;
use Cbox\Cms\Panel\Boundary\Generated\CommandFormPageCodecV1;
use Cbox\Cms\Panel\Boundary\Generated\ForgotPasswordPageCodecV1;
use Cbox\Cms\Panel\Boundary\Generated\HomePageCodecV1;
use Cbox\Cms\Panel\Boundary\Generated\LoginPageCodecV1;
use Cbox\Cms\Panel\Boundary\Generated\NotFoundPageCodecV1;
use Cbox\Cms\Panel\Boundary\Generated\ResetPasswordPageCodecV1;
use Cbox\Cms\Panel\Contributions\Boundary\SharedProps;
use Cbox\Cms\Panel\Contributions\Domain\Dto\ActiveFill;
use Cbox\Cms\Panel\Domain\Dto\AccountMePage;
use Cbox\Cms\Panel\Domain\Dto\AddonPage;
use Cbox\Cms\Panel\Domain\Dto\CommandFormPage;
use Cbox\Cms\Panel\Domain\Dto\ForgotPasswordPage;
use Cbox\Cms\Panel\Domain\Dto\ForgotPasswordRefusals;
use Cbox\Cms\Panel\Domain\Dto\HomePage;
use Cbox\Cms\Panel\Domain\Dto\LoginPage;
use Cbox\Cms\Panel\Domain\Dto\LoginRefusals;
use Cbox\Cms\Panel\Domain\Dto\NotFoundPage;
use Cbox\Cms\Panel\Domain\Dto\ReadAnswer;
use Cbox\Cms\Panel\Domain\Dto\ResetPasswordPage;
use Cbox\Cms\Panel\Domain\Dto\ResetPasswordRefusals;
use Cbox\Cms\Panel\Domain\ForgotPasswordRefusal;
use Cbox\Cms\Panel\Domain\LoginRefusal;
use Cbox\Cms\Panel\Domain\PanelRoute;
use Cbox\Cms\Panel\Domain\ResetFormRefusal;
use Cbox\Cms\Panel\Domain\ResetPasswordRefusal;
use Cbox\Cms\Panel\Domain\SignInReason;
use Cbox\Cms\Panel\Shell\Domain\OwnPage;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Illuminate\Support\ViewErrorBag;
use Inertia\ResponseFactory;
use LogicException;

/**
 * Renders the panel's pages that need no action (PRD 13.4, 5.16). Each page's props are a DTO of
 * the panel's Domain\Dto, written by the page's generated codec (GUARDRAILS 2.2), whose generated
 * TypeScript the page in js/panel imports; the schemas are in packages/panel/resources/schemas/pages.
 * The refusal of a form just posted, which LoginForm and PasswordResetForms flash into Inertia's
 * errors for the redirect, is read back into the page's typed refusals, a code the page knows or
 * null:
 *
 * - the page for a path the panel does not have, with the address of the panel's start, the
 *   prefix of the route that matched, so it can link back;
 * - the login page, with the address its form posts to, the address of the page that asks for a
 *   password reset link, and the reason the panel sent the browser there, a SignInReason the
 *   address names, or null;
 * - the page that asks for a password reset link, with the address its form posts to, the login
 *   page's, whether a request was just taken, and for how many minutes a link works;
 * - the page a reset link opens, with the address its form posts to, the token of the link, or
 *   null when the address holds no text in the form of a token, and the addresses of the other two
 *   pages. Its answer is never cached and sends no Referer, because its address holds the token;
 * - the start page of a person who logged in, with the address of the logout and the props of
 *   its contributions, cms.contributions, which every page behind the login sends (ContributionProps;
 *   the start page renders the shell's points). Like every page behind the login, its
 *   answer is never cached (PRIVATE_CACHE_CONTROL), because the props of a
 *   page behind the login can hold personal fields (PRD 12.2) that no browser cache, back/forward
 *   cache after logout or shared proxy may keep;
 * - an addon's page (PRD 13.4), at `<prefix>/x/<namespace>/<path>`, with the page's id, the
 *   addon's namespace and the address of the logout, beside its contributions, among which the
 *   page's own data comes as the deferred prop of its addon;
 * - the who-am-I page (PRD 5.16), at `<prefix>/account/me`, with the address of the logout and the
 *   read of actor.me as the person (PanelReads), the result's document or the rejection's problem
 *   details, beside its contributions;
 * - the generic command form (PRD 6.1), at `<prefix>/commands/<name>/v<version>`, with the address
 *   of the logout, the command's name and version and its JSON Schema as a document, from which
 *   the form is rendered, beside its contributions.
 */
#[Internal]
final readonly class PanelPages
{
    /** The Inertia page for a path the panel does not have, in js/panel/src/pages. */
    public const string NOT_FOUND = 'Errors/NotFound';

    /** The login page, in js/panel/src/pages. */
    public const string LOGIN = 'Auth/Login';

    /** The start page, in js/panel/src/pages. */
    public const string HOME = 'Home';

    /** The start page's name, which a panel point it renders names as its page (PRD 13.4). */
    public const string HOME_PAGE = OwnPage::Home->value;

    /** The who-am-I page, in js/panel/src/pages, which shows the person their own actor, profile and grants. */
    public const string ACCOUNT_ME = 'Account/Me';

    /** An addon's page, in js/panel/src/pages, which renders the addon's component of the page. */
    public const string ADDON = 'Addon';

    /** The generic command form, in js/panel/src/pages, rendered from the command's JSON Schema. */
    public const string COMMAND_FORM = 'Command';

    /** The page that asks for a password reset link, in js/panel/src/pages. */
    public const string FORGOT_PASSWORD = 'Auth/ForgotPassword';

    /** The page a password reset link opens, in js/panel/src/pages. */
    public const string RESET_PASSWORD = 'Auth/ResetPassword';

    /** The Cache-Control of the pages behind the login and of the page a reset link opens. */
    public const string PRIVATE_CACHE_CONTROL = 'no-store, private';

    public function __construct(
        private ResponseFactory $inertia,
        private UrlGenerator $urls,
        private PasswordResetForms $resets,
        private LoginPageCodecV1 $loginPage,
        private ForgotPasswordPageCodecV1 $forgotPasswordPage,
        private ResetPasswordPageCodecV1 $resetPasswordPage,
        private HomePageCodecV1 $homePage,
        private NotFoundPageCodecV1 $notFoundPage,
        private AddonPageCodecV1 $addonPage,
        private AccountMePageCodecV1 $accountMePage,
        private CommandFormPageCodecV1 $commandFormPage,
    ) {}

    public function login(Request $request): Response|JsonResponse
    {
        $reason = $request->query(SignInReason::PARAMETER);
        $refusal = static fn (string $field): ?LoginRefusal => LoginRefusal::tryFrom(self::refusal($request, $field));

        return $this->render($request, self::LOGIN, $this->loginPage->encode(new LoginPage(
            action: $this->urls->route(PanelRoute::LoginSubmit->value, [], false),
            forgot: $this->urls->route(PanelRoute::ForgotPassword->value, [], false),
            reason: is_string($reason) ? SignInReason::tryFrom($reason) : null,
            refusals: new LoginRefusals(
                email: $refusal(LoginForm::EMAIL),
                form: $refusal(LoginForm::FORM),
                password: $refusal(LoginForm::PASSWORD),
            ),
        ), ClassificationAccess::Public));
    }

    public function forgotPassword(Request $request, ResetSettings $settings): Response|JsonResponse
    {
        return $this->render($request, self::FORGOT_PASSWORD, $this->forgotPasswordPage->encode(new ForgotPasswordPage(
            action: $this->urls->route(PanelRoute::ForgotPasswordSubmit->value, [], false),
            login: $this->urls->route(PanelRoute::Login->value, [], false),
            minutes: $settings->tokenMinutes,
            refusals: new ForgotPasswordRefusals(ForgotPasswordRefusal::tryFrom(self::refusal($request, PasswordResetForms::EMAIL))),
            requested: $this->resets->wasRequested($request),
        ), ClassificationAccess::Public));
    }

    public function resetPassword(Request $request, string $token): Response|JsonResponse
    {
        $response = $this->render($request, self::RESET_PASSWORD, $this->resetPasswordPage->encode(new ResetPasswordPage(
            action: $this->urls->route(PanelRoute::ResetPasswordSubmit->value, [], false),
            forgot: $this->urls->route(PanelRoute::ForgotPassword->value, [], false),
            login: $this->urls->route(PanelRoute::Login->value, [], false),
            refusals: new ResetPasswordRefusals(
                form: ResetFormRefusal::tryFrom(self::refusal($request, PasswordResetForms::FORM)),
                password: ResetPasswordRefusal::tryFrom(self::refusal($request, PasswordResetForms::PASSWORD)),
            ),
            token: PasswordResetToken::parse($token)?->reveal(),
        ), ClassificationAccess::Public));
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $this->unstored($response);
    }

    public function home(Request $request, SharedProps $contributions): Response|JsonResponse
    {
        return $this->unstored($this->render($request, self::HOME, $this->homePage->encode(
            new HomePage($this->urls->route(PanelRoute::Logout->value, [], false)),
            ClassificationAccess::Public,
        ), $contributions));
    }

    /**
     * An addon's page, the active PageContribution given, with the contributions active on it,
     * its own data among them.
     */
    public function addonPage(Request $request, ActiveFill $page, SharedProps $contributions): Response|JsonResponse
    {
        return $this->unstored($this->render($request, self::ADDON, $this->addonPage->encode(
            new AddonPage($page->fill->contribution, $page->fill->addon(), $this->urls->route(PanelRoute::Logout->value, [], false)),
            ClassificationAccess::Public,
        ), $contributions));
    }

    /**
     * The who-am-I page, with the read of actor.me as the person, the result or the problem, and
     * the contributions active on it (PRD 5.16, 13.4).
     */
    public function accountMe(Request $request, ReadAnswer $read, SharedProps $contributions): Response|JsonResponse
    {
        return $this->unstored($this->render($request, self::ACCOUNT_ME, $this->accountMePage->encode(
            new AccountMePage($this->urls->route(PanelRoute::Logout->value, [], false), $read->result, $read->rejection),
            ClassificationAccess::Public,
        ), $contributions));
    }

    /**
     * The generic command form of the command, with its JSON Schema, and the contributions active
     * on it (PRD 6.1, 13.4).
     */
    public function commandForm(Request $request, CommandFormPage $form, SharedProps $contributions): Response|JsonResponse
    {
        return $this->unstored($this->render($request, self::COMMAND_FORM, $this->commandFormPage->encode($form, ClassificationAccess::Public), $contributions));
    }

    public function notFound(Request $request): Response|JsonResponse
    {
        $response = $this->render($request, self::NOT_FOUND, $this->notFoundPage->encode(new NotFoundPage($this->start($request)), ClassificationAccess::Public));
        $response->setStatusCode(404);

        return $response;
    }

    /**
     * The catalog code a form flashed into Inertia's errors under $field for the page the
     * redirect renders, LoginForm's and PasswordResetForms', or '' when it flashed none.
     */
    private static function refusal(Request $request, string $field): string
    {
        $errors = $request->hasSession() ? $request->session()->get('errors') : null;

        return $errors instanceof ViewErrorBag ? $errors->getBag('default')->first($field) : '';
    }

    /**
     * The page with the props a generated codec wrote as $json, and for a page behind the login the
     * props of its contributions (ContributionProps).
     */
    private function render(Request $request, string $page, string $json, SharedProps $contributions = new SharedProps): Response|JsonResponse
    {
        $response = $this->inertia->render($page, [...InertiaProps::document($json), ...$contributions->props])->toResponse($request);

        if (! $response instanceof Response && ! $response instanceof JsonResponse) {
            throw new LogicException('Inertia answered the panel page with a response of another kind.');
        }

        return $response;
    }

    private function unstored(Response|JsonResponse $response): Response|JsonResponse
    {
        $response->headers->set('Cache-Control', self::PRIVATE_CACHE_CONTROL);

        return $response;
    }

    /**
     * The panel's start: the root of the prefix the matched route was registered with.
     */
    private function start(Request $request): string
    {
        $route = $request->route();
        $prefix = $route instanceof Route ? $route->getPrefix() : null;

        return '/'.trim((string) $prefix, '/');
    }
}
