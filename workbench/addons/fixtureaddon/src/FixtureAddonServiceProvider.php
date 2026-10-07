<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon;

use Cbox\Cms\Contracts\Addons\AddonCapabilities;
use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Addons\AllowedHook;
use Cbox\Cms\Contracts\Addons\CoreApiVersion;
use Cbox\Cms\Contracts\Addons\SchemaContributions;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Build\DeclaresAddon;
use Cbox\Cms\Contracts\Build\DeclaresScanRoots;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\ActionContribution;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\Confirm;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\DecoratorContribution;
use Cbox\Cms\Contracts\PanelPoints\FlowStep;
use Cbox\Cms\Contracts\PanelPoints\FormCheck;
use Cbox\Cms\Contracts\PanelPoints\LoginNotice;
use Cbox\Cms\Contracts\PanelPoints\NavContribution;
use Cbox\Cms\Contracts\PanelPoints\ObserverContribution;
use Cbox\Cms\Contracts\PanelPoints\PageContribution;
use Cbox\Cms\Contracts\PanelPoints\PanelApiVersion;
use Cbox\Cms\Contracts\PanelPoints\PanelContribution;
use Cbox\Cms\Contracts\PanelPoints\PanelContributions;
use Cbox\Cms\Contracts\PanelPoints\ReplacementContribution;
use Cbox\Cms\Contracts\PanelPoints\Scope;
use Cbox\Cms\Contracts\PanelPoints\Severity;
use Cbox\Cms\Contracts\PanelPoints\SlotFill;
use Cbox\Cms\Contracts\PanelPoints\StepPosition;
use Cbox\Cms\Contracts\PanelPoints\Tighten;
use Cbox\Cms\Contracts\PanelPoints\Tone;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Access\Domain\Commands\AssignGrant;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry;
use Cbox\Cms\Core\Entries\Domain\Commands\ReleaseVariant;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Illuminate\Support\ServiceProvider;
use Workbench\FixtureAddon\Articles\Adapter\PostgresFixtureArticles;
use Workbench\FixtureAddon\Articles\Boundary\ArticlesCodecs;
use Workbench\FixtureAddon\Articles\Domain\FixtureArticles;
use Workbench\FixtureAddon\Articles\Domain\Queries\ListFixtureArticles;
use Workbench\FixtureAddon\Slug\Adapter\KernelArticleHeads;
use Workbench\FixtureAddon\Slug\Boundary\SlugCodecs;
use Workbench\FixtureAddon\Slug\Domain\ArticleHeads;
use Workbench\FixtureAddon\Slug\Domain\ArticleSlug;

/**
 * The service provider of the workbench's fixture addon, cboxdk/cms-fixture-addon (PRD 13.1 to
 * 13.4, MILESTONES M1 point 7). Its scan root holds the addon's hooks, its query and the query's
 * action, and its manifest says what the addon does through the kernel: it is named fixtureaddon,
 * needs the core API 1.0, reads public fields only, may transform and validate entry.create,
 * validate variant.release and authorize grant.assign, and extends app:fixture_article with the
 * blueprint in its schema directory. It registers its query codec under QueryCodecs::TAG and
 * binds the port its query reads through, as an addon with a query of its own does; cms:build
 * compiles the rest from the scan root and the manifest, and cms:generate reads the blueprint from
 * the schema root the application names for the owner fixtureaddon.
 *
 * In the panel (PRD 13.4) it contributes to every kind of contribution and every point of block B1,
 * so the panel's extension model is exercised end to end by the browser tests of the workbench
 * (tests/Browser/Panel/PanelAddonsTest.php, PanelInpTest.php):
 *
 * - on the generic command form of entry.create (section 8 of the panel extension architecture):
 *   the checks SLUG_HINT, a warning where the title derives no slug, SLUG_OVERRIDE, which asks the
 *   viewer to acknowledge a slug set by hand, and SLUG_SHAPE, which blocks a slug that is not well
 *   formed and mirrors the hook RequireWellFormedSlug, as the mirror rule asks; the step
 *   SLUG_REVIEW before the submit, which shows the slug the article gets, patches it into the
 *   draft or cancels; the aside SLUG_HELP, which explains the slug; the decorator SUBMIT_NOTE,
 *   which adds a description to the form's actions; the decorator RECEIPT_NOTE around the
 *   receipt; and DRY_RUN_NOTE below what a dry run would change;
 * - on the form of its own command fixtureaddon.slug.set (Slug\Domain\Commands\SetArticleSlug,
 *   the action SetArticleSlugAction on REST and Inertia over the port ArticleHeads, which sets
 *   the addon's slug of an article in a new revision): the replacement SLUG_INPUT at
 *   command.form.field@1, keyed by the addon's own value class ArticleSlug, which the command
 *   binds its member `slug` to, so the addon's input, which shapes what is typed into a slug,
 *   takes the place of the default input of that member; a replacement at the point is owned
 *   through the value class, and the kernel's own ids stay with the core's pickers;
 * - on the form of grant.assign: the check SELF_GRANT, which blocks a grant to the viewer
 *   themselves and mirrors the authorize hook DenySelfGrant, the addon's four-eyes rule, and the
 *   step FOUR_EYES before the submit, which asks the viewer to confirm that a second person
 *   reviewed the grant; the step is a courtesy, the hook is the rule;
 * - in the shell: the page ARTICLES at /x/fixtureaddon/articles, which lists the articles of the
 *   type the addon extends with their slugs through the addon's query fixtureaddon.articles, the
 *   nav entry ARTICLES_LINK that opens it, both for a viewer who holds fixtureaddon.articles, the
 *   action NEW_ARTICLE of the viewer's menu, which opens the form of entry.create for a viewer
 *   who may create entries, and the observer ACTIVITY, which records each command that completed
 *   in the browser's session storage;
 * - on the who-am-I page: RECENT_ACTIVITY, the section that shows what the observer recorded,
 *   MY_ARTICLES, the section that lists the articles through the same query, and FAULTY, a
 *   section that throws when it renders, which the workbench disables through the kill switch
 *   (cbox-cms.panel.disabled) so that the panel's isolation of a failing contribution can be
 *   seen and tested; on the grants page FOUR_EYES_NOTE and on the roles page
 *   ARTICLES_PERMISSION_NOTE, each a note about one of the addon's rules;
 * - on the login page the notice LOGIN_NOTICE, data alone, because no addon code runs there;
 * - and one theme, brand, a magenta accent, which has no effect until the installation selects it
 *   in cbox-cms.panel.themes; the workbench does not.
 *
 * The addon reads public fields, so the panel hands its contributions the query's result at
 * public access: an article's title, classified internal, is never among what MY_ARTICLES and
 * ARTICLES get, whatever the viewer may read (the reads cap of section 3.2). Their code is the
 * prebuilt bundle in dist/panel, built from resources/panel by `npm run build:fixture-addon` and
 * signed with the test key panel-signing-test-key.pem, whose public key the workbench trusts in
 * cbox-cms.addons.publishers (PRD 13.8).
 */
final class FixtureAddonServiceProvider extends ServiceProvider implements DeclaresAddon, DeclaresScanRoots
{
    /** The addon's Composer package, which its scan root and manifest name. */
    public const string PACKAGE = 'cboxdk/cms-fixture-addon';

    /** The addon's namespace, of its extension fields. */
    public const string NAMESPACE = 'fixtureaddon';

    /** The name of the addon's panel theme, which the installation selects as fixtureaddon:brand. */
    public const string THEME = 'brand';

    /** The form of entry.create, which the addon's checks and step apply to. */
    public const string ENTRY_CREATE = 'entry.create@1';

    /** The form of grant.assign, which the addon's self-grant check and four-eyes step apply to. */
    public const string GRANT_ASSIGN = 'grant.assign@1';

    /** The warning check: the title derives no slug. */
    public const string SLUG_HINT = 'fixtureaddon.slug-hint';

    /** The acknowledge check: a slug set by hand, instead of the derived one. */
    public const string SLUG_OVERRIDE = 'fixtureaddon.slug-override';

    /** The blocking check, which mirrors RequireWellFormedSlug: a slug that is not well formed. */
    public const string SLUG_SHAPE = 'fixtureaddon.slug-shape';

    /** The step before the submit: the slug the article gets, patched into the draft or cancelled. */
    public const string SLUG_REVIEW = 'fixtureaddon.slug-review';

    /** The path the step may patch: the addon's own field. */
    public const string SLUG_PATH = 'fields.ext.fixtureaddon.fixture_slug';

    /** The aside of entry.create's form: what a slug is and where it comes from. */
    public const string SLUG_HELP = 'fixtureaddon.slug-help';

    /** The decorator of entry.create's actions: a description of what the addon does on the run. */
    public const string SUBMIT_NOTE = 'fixtureaddon.submit-note';

    /** The decorator of entry.create's receipt: a note after the run. */
    public const string RECEIPT_NOTE = 'fixtureaddon.receipt-note';

    /** The section below what a dry run of entry.create would change. */
    public const string DRY_RUN_NOTE = 'fixtureaddon.dry-run-note';

    /** The blocking check of grant.assign, which mirrors DenySelfGrant: a grant to oneself. */
    public const string SELF_GRANT = 'fixtureaddon.self-grant';

    /** The step before the submit of grant.assign: a second person reviewed the grant. */
    public const string FOUR_EYES = 'fixtureaddon.four-eyes';

    /** The addon's page: the articles of the type it extends, with their slugs. */
    public const string ARTICLES = 'fixtureaddon.articles';

    /** The path of ARTICLES below /x/fixtureaddon/. */
    public const string ARTICLES_PATH = 'articles';

    /** The nav entry that opens ARTICLES. */
    public const string ARTICLES_LINK = 'fixtureaddon.articles-link';

    /** The permission ARTICLES, ARTICLES_LINK and MY_ARTICLES need: the addon's query. */
    public const string ARTICLES_PERMISSION = 'fixtureaddon.articles';

    /** The action of the viewer's menu that opens the form of entry.create. */
    public const string NEW_ARTICLE = 'fixtureaddon.new-article';

    /** The observer of every command that completed, which records it in the session storage. */
    public const string ACTIVITY = 'fixtureaddon.activity';

    /** The section of the who-am-I page that shows what ACTIVITY recorded. */
    public const string RECENT_ACTIVITY = 'fixtureaddon.recent-activity';

    /** The section of the who-am-I page that lists the articles through fixtureaddon.articles. */
    public const string MY_ARTICLES = 'fixtureaddon.my-articles';

    /** The section of the who-am-I page that throws when it renders, disabled in the workbench. */
    public const string FAULTY = 'fixtureaddon.faulty';

    /** The section of the grants page about the four-eyes rule. */
    public const string FOUR_EYES_NOTE = 'fixtureaddon.four-eyes-note';

    /** The section of the roles page about the permission the addon's pages need. */
    public const string ARTICLES_PERMISSION_NOTE = 'fixtureaddon.articles-permission';

    /** The notice above the login form. */
    public const string LOGIN_NOTICE = 'fixtureaddon.login-notice';

    /** The form of the addon's own command, fixtureaddon.slug.set, whose slug input the addon replaces. */
    public const string SLUG_SET = 'fixtureaddon.slug.set@1';

    /** The permission the command needs: its own name. */
    public const string SLUG_SET_PERMISSION = 'fixtureaddon.slug.set';

    /** The replacement of the input of a member bound to ArticleSlug, the addon's own value class. */
    public const string SLUG_INPUT = 'fixtureaddon.slug-input';

    /** The priorities of the who-am-I page's sections, in the order the host renders them. */
    public const int RECENT_ACTIVITY_PRIORITY = 10;

    public const int MY_ARTICLES_PRIORITY = 20;

    public const int FAULTY_PRIORITY = 30;

    /**
     * The experimental points the addon accepts: every point of block B1 it contributes to.
     *
     * @var list<string>
     */
    public const array ACCEPTS_EXPERIMENTAL = [
        'access.grants.sections@1',
        'access.roles.sections@1',
        'account.me.sections@1',
        'command.form.aside@1',
        'command.form.checks@1',
        'command.form.dryrun@1',
        'command.form.field@1',
        'command.form.receipt@1',
        'command.form.steps@1',
        'command.form.submit@1',
        'login.notice@1',
        'panel.observe.command@1',
        'shell.nav@1',
        'shell.page@1',
        'shell.user-menu@1',
    ];

    public function register(): void
    {
        $this->app->bind(FixtureArticles::class, PostgresFixtureArticles::class);
        $this->app->bind(ArticleHeads::class, KernelArticleHeads::class);

        $query = QueryCodecs::TAG.'.'.ArticlesCodecs::QUERY.'.v'.ArticlesCodecs::VERSION;
        $this->app->instance($query, ArticlesCodecs::articles());
        $this->app->tag($query, QueryCodecs::TAG);

        $command = CommandCodecs::TAG.'.'.SlugCodecs::COMMAND.'.v'.SlugCodecs::VERSION;
        $this->app->instance($command, SlugCodecs::setSlug());
        $this->app->tag($command, CommandCodecs::TAG);
    }

    public function scanRoots(): array
    {
        return [new ScanRoot(self::PACKAGE, __DIR__)];
    }

    public function addonManifest(): AddonManifest
    {
        return new AddonManifest(
            package: self::PACKAGE,
            namespace: new AddonNamespace(self::NAMESPACE),
            coreApi: new CoreApiVersion(1, 0),
            docs: __DIR__.'/../docs',
            capabilities: new AddonCapabilities(reads: ClassificationAccess::Public, issues: [CreateEntry::class], uiTheme: true),
            hooks: [
                new AllowedHook(CreateEntry::class, Phase::Transform),
                new AllowedHook(CreateEntry::class, Phase::Validate),
                new AllowedHook(ReleaseVariant::class, Phase::Validate),
                new AllowedHook(AssignGrant::class, Phase::Authorize),
            ],
            schema: new SchemaContributions(
                extends: [new TypeName(FixtureArticle::TYPE)],
                directory: __DIR__.'/../schema',
            ),
            panel: new PanelContributions(
                sdk: new PanelApiVersion(1, 0),
                bundle: __DIR__.'/../dist/panel',
                acceptsExperimental: self::ACCEPTS_EXPERIMENTAL,
                contributions: self::contributions(),
                themes: [self::THEME => __DIR__.'/../resources/panel/theme.json'],
            ),
        );
    }

    /**
     * The addon's panel contributions: one of every kind, on every point of block B1.
     *
     * @return list<PanelContribution>
     */
    public static function contributions(): array
    {
        $entryCreate = new Scope(commands: [CommandRef::fromString(self::ENTRY_CREATE)]);
        $articles = new Scope(requires: new CommandName(self::ARTICLES_PERMISSION));

        return [
            // The form of entry.create.
            new FormCheck(new ContributionId(self::SLUG_HINT), 'command.form.checks@1', self::ENTRY_CREATE, Severity::Warning),
            new FormCheck(new ContributionId(self::SLUG_OVERRIDE), 'command.form.checks@1', self::ENTRY_CREATE, Severity::Acknowledge),
            new FormCheck(new ContributionId(self::SLUG_SHAPE), 'command.form.checks@1', self::ENTRY_CREATE, Severity::Error, RequireWellFormedSlug::class),
            new FlowStep(new ContributionId(self::SLUG_REVIEW), 'command.form.steps@1', self::ENTRY_CREATE, StepPosition::BeforeSubmit, [self::SLUG_PATH]),
            new SlotFill(new ContributionId(self::SLUG_HELP), 'command.form.aside@1', scope: $entryCreate),
            new DecoratorContribution(new ContributionId(self::SUBMIT_NOTE), 'command.form.submit@1', [Tighten::Description], scope: $entryCreate),
            new DecoratorContribution(new ContributionId(self::RECEIPT_NOTE), 'command.form.receipt@1', scope: $entryCreate),
            new SlotFill(new ContributionId(self::DRY_RUN_NOTE), 'command.form.dryrun@1', scope: $entryCreate),
            // The form of the addon's own command: the input of its own value class.
            new ReplacementContribution(new ContributionId(self::SLUG_INPUT), 'command.form.field@1', ArticleSlug::class),
            // The form of grant.assign.
            new FormCheck(new ContributionId(self::SELF_GRANT), 'command.form.checks@1', self::GRANT_ASSIGN, Severity::Error, DenySelfGrant::class),
            new FlowStep(new ContributionId(self::FOUR_EYES), 'command.form.steps@1', self::GRANT_ASSIGN, StepPosition::BeforeSubmit),
            // The shell.
            new PageContribution(new ContributionId(self::ARTICLES), 'shell.page@1', self::ARTICLES_PATH, ListFixtureArticles::class, scope: $articles),
            new NavContribution(new ContributionId(self::ARTICLES_LINK), 'shell.nav@1', 'fixtureaddon.nav.articles', self::ARTICLES, 'menu', scope: $articles),
            new ActionContribution(new ContributionId(self::NEW_ARTICLE), 'shell.user-menu@1', CreateEntry::class, 'fixtureaddon.new_article.label', 'plus', confirm: Confirm::Form),
            new ObserverContribution(new ContributionId(self::ACTIVITY), 'panel.observe.command@1'),
            // The who-am-I, grants and roles pages.
            new SlotFill(new ContributionId(self::RECENT_ACTIVITY), 'account.me.sections@1', priority: self::RECENT_ACTIVITY_PRIORITY),
            new SlotFill(new ContributionId(self::MY_ARTICLES), 'account.me.sections@1', ListFixtureArticles::class, self::MY_ARTICLES_PRIORITY, $articles),
            new SlotFill(new ContributionId(self::FAULTY), 'account.me.sections@1', priority: self::FAULTY_PRIORITY),
            new SlotFill(new ContributionId(self::FOUR_EYES_NOTE), 'access.grants.sections@1'),
            new SlotFill(new ContributionId(self::ARTICLES_PERMISSION_NOTE), 'access.roles.sections@1'),
            // The login page.
            new LoginNotice(new ContributionId(self::LOGIN_NOTICE), 'login.notice@1', 'fixtureaddon.login_notice.message', Tone::Info),
        ];
    }
}
