<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Codecs\JsonDocument;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\PanelPoints\ActionContribution;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\DecoratorContribution;
use Cbox\Cms\Contracts\PanelPoints\FlowStep;
use Cbox\Cms\Contracts\PanelPoints\FormCheck;
use Cbox\Cms\Contracts\PanelPoints\NavContribution;
use Cbox\Cms\Contracts\PanelPoints\PageContribution;
use Cbox\Cms\Contracts\PanelPoints\PageName;
use Cbox\Cms\Contracts\PanelPoints\PanelLocale;
use Cbox\Cms\Contracts\PanelPoints\ReplacementContribution;
use Cbox\Cms\Contracts\Pipeline\Result;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Codecs\Domain\EncodingFailed;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCodec;
use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Http\Credentials\Boundary\RequestCredential;
use Cbox\Cms\Http\Inertia\Boundary\InertiaProps;
use Cbox\Cms\Panel\Boundary\Generated\ContributionsCodecV1;
use Cbox\Cms\Panel\Boundary\PanelSessions;
use Cbox\Cms\Panel\Contributions\Domain\ContributionTelemetry;
use Cbox\Cms\Panel\Contributions\Domain\DataRefusal;
use Cbox\Cms\Panel\Contributions\Domain\Dto\ActiveContributions;
use Cbox\Cms\Panel\Contributions\Domain\Dto\ActiveFill;
use Cbox\Cms\Panel\Contributions\Domain\Dto\AddonRegistration;
use Cbox\Cms\Panel\Contributions\Domain\Dto\AddonTexts;
use Cbox\Cms\Panel\Contributions\Domain\Dto\ContributionData;
use Cbox\Cms\Panel\Contributions\Domain\Dto\ContributionDataCall;
use Cbox\Cms\Panel\Contributions\Domain\Dto\PanelView;
use Cbox\Cms\Panel\Contributions\Domain\Dto\PointCodec;
use Cbox\Cms\Panel\Contributions\Domain\Dto\RenderedPoint;
use Cbox\Cms\Panel\Contributions\Domain\Dto\ViewSubject;
use Cbox\Cms\Panel\Contributions\Domain\PointCodecs;
use Cbox\Cms\Panel\Contributions\Domain\Withheld;
use Cbox\Cms\Panel\Domain\Dto\ActionProp;
use Cbox\Cms\Panel\Domain\Dto\AddonProp;
use Cbox\Cms\Panel\Domain\Dto\AddonTextsProp;
use Cbox\Cms\Panel\Domain\Dto\CheckProp;
use Cbox\Cms\Panel\Domain\Dto\ContributionsProp;
use Cbox\Cms\Panel\Domain\Dto\DecoratorProp;
use Cbox\Cms\Panel\Domain\Dto\FillProp;
use Cbox\Cms\Panel\Domain\Dto\NavProp;
use Cbox\Cms\Panel\Domain\Dto\PageLinkProp;
use Cbox\Cms\Panel\Domain\Dto\PointFillsProp;
use Cbox\Cms\Panel\Domain\Dto\PrefillProp;
use Cbox\Cms\Panel\Domain\Dto\ReplacementProp;
use Cbox\Cms\Panel\Domain\Dto\StepProp;
use Cbox\Cms\Panel\Domain\Dto\TextProp;
use Cbox\Cms\Panel\Domain\PanelRoute;
use Cbox\Cms\Panel\Shell\Domain\Dto\ShellNavV1;
use Cbox\Cms\Panel\Shell\Domain\Dto\ShellPageV1;
use Cbox\Cms\Panel\Shell\Domain\Dto\ViewerSummaryV1;
use Cbox\Cms\Panel\Shell\Domain\OwnPage;
use Cbox\Cms\Panel\Shell\Domain\Shell;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use Inertia\DeferProp;
use LogicException;
use stdClass;
use Throwable;

/**
 * The props a panel page behind the login sends for the contributions to the points it renders
 * (PRD 13.4), beside its own props, which PanelPages adds to the page:
 *
 * - CMS.CONTRIBUTIONS, `cms.contributions`: the contributions active for the viewer, as the action
 *   ResolveContributions works them out for the PanelView that view() reads from the request, the
 *   shell's points among the page's,
 *   written by the generated ContributionsCodecV1 (contributions.v1.json), each with the point's
 *   props as the point's codec (PointCodecs) wrote them at the fill's access, the lower of the
 *   viewer's and the addon's reads, or null for a point whose props the page holds in the browser
 *   (RenderedPoint::heldByPage()), and what its kind needs besides; each point with its kind,
 *   region and multiplicity; the registration of each addon whose code runs on the page; whether
 *   the viewer sees the detail of a failure; the panel's pages a contribution may navigate to,
 *   the panel's own pages (OwnPage) and every addon's page the viewer may open, each at
 *   `<prefix>/x/<namespace>/<path>`; the texts of the active locale, the application's, for each
 *   addon with an active contribution, as cms:build compiled its catalogue (Catalogues), so a
 *   contribution's t() gives its text and no other locale is ever sent; and
 *   the address of the Inertia profile the host runs commands through, which is everything the
 *   panel's host (js/panel/src/host) renders the points from. A point without a codec, or props
 *   its codec cannot write, loses its contributions, recorded in telemetry
 *   (ContributionTelemetry::withheld());
 * - DATA, `ext.<addon>`: per addon whose contributions read data, a deferred prop in the group of
 *   the addon's namespace, which the host asks for after the page has rendered. It holds, under
 *   each such contribution's id, the result of its query as the action RunContributionData gives
 *   it, and nothing for a contribution whose query was refused, rejected or failed, so the page
 *   answers whatever one addon's query does. The query's input is taken from the props the fill
 *   was handed (QueryInput) and read by the query's codec (QueryCodecs), whose result codec writes
 *   the answer at the access the read had; a failure is reported to the application's exception
 *   handler.
 *
 * The viewer is the principal and the credential PanelSessions put on the request when it
 * authenticated it. A controller hands the actions' methods in as closures, because Boundary does
 * not use actions (GUARDRAILS 2.5).
 */
#[Internal]
final readonly class ContributionProps
{
    /** The prop that holds the panel's own shared props. */
    public const string CMS = 'cms';

    /** The member of CMS that holds the active contributions. */
    public const string CONTRIBUTIONS = 'contributions';

    /** The prop that holds each addon's data, under its namespace. */
    public const string DATA = 'ext';

    public function __construct(
        private ContributionsCodecV1 $codec,
        private PointCodecs $points,
        private QueryCodecs $queries,
        private ContributionTelemetry $telemetry,
        private ExceptionHandler $exceptions,
        private UrlGenerator $urls,
        private Application $app,
    ) {}

    /**
     * The view of a page for the viewer the panel authenticated on the request: the page's own
     * points, then the shell's, which every page behind the login renders around its content: the
     * navigation, the addons' pages, the viewer's menu, whose props are the viewer, and the
     * observers of a command that completed, whose event the page holds.
     *
     * @param  list<RenderedPoint>  $points  the points the page renders, in its order
     *
     * @throws LogicException for a request the panel did not authenticate
     */
    public function view(Request $request, string $page, array $points = [], ViewSubject $subject = new ViewSubject): PanelView
    {
        $viewer = $this->viewer($request, $page);

        return new PanelView(new PageName($page), $viewer, [
            ...$points,
            new RenderedPoint(Shell::nav(), new ShellNavV1),
            new RenderedPoint(Shell::pages(), new ShellPageV1),
            new RenderedPoint(Shell::userMenu(), new ViewerSummaryV1($viewer->actor, $viewer->issuerKind)),
            RenderedPoint::heldByPage(Shell::observe()),
        ], $subject, PanelLocale::of($this->app->getLocale()));
    }

    /**
     * The viewer the panel authenticated on the request, whose actor a page hands the points it
     * renders as their props.
     *
     * @throws LogicException for a request the panel did not authenticate
     */
    public function viewer(Request $request, string $page): ActorPrincipal
    {
        $viewer = $request->attributes->get(PanelSessions::PRINCIPAL);

        if (! $viewer instanceof ActorPrincipal) {
            throw new LogicException(sprintf('The panel page %s shows contributions only for a request the panel authenticated.', $page));
        }

        return $viewer;
    }

    /**
     * The page's contribution props.
     *
     * @param  Closure(ContributionDataCall): ContributionData  $run  RunContributionData::run()
     * @param  Closure(PageName, ActiveFill, DataRefusal): ContributionData  $refuse  RunContributionData::refused()
     *
     * @throws LogicException for a request the panel did not authenticate
     */
    public function props(Request $request, ActiveContributions $active, Closure $run, Closure $refuse): SharedProps
    {
        $credential = RequestCredential::of($request);

        if (! $credential instanceof TransportCredential) {
            throw new LogicException(sprintf('The panel page %s shows contributions only for a request the panel authenticated.', $active->page->value));
        }

        $points = [];
        $data = [];

        foreach ($active->points as $point) {
            $fills = [];

            foreach ($point->fills as $fill) {
                $props = $fill->props === null ? null : $this->encoded($active->page, $fill);

                if ($fill->props !== null && ! $props instanceof JsonDocument) {
                    continue;
                }

                $query = $fill->data();
                $fills[] = $this->fill($fill, $props);

                if ($query instanceof CommandRef) {
                    $data[$fill->fill->addon()->value][] = new EncodedFill($fill, $props);
                }
            }

            if ($fills !== []) {
                $declaration = $point->declaration;
                $points[] = new PointFillsProp($point->point->toString(), $fills, $declaration->kind, $declaration->region, $declaration->multiplicity, $declaration->max);
            }
        }

        $prop = new ContributionsProp(
            $points,
            array_map(static fn (AddonRegistration $addon): AddonProp => new AddonProp(
                $addon->addon,
                $addon->digest,
                array_map(static fn (CommandRef $command): string => $command->toString(), $addon->issues),
                $addon->anyCommand,
            ), $active->registrations),
            $this->home().'/'.PanelRoute::COMMANDS_PATH,
            $active->details,
            $this->pages($active),
            $this->viewer($request, $active->page->value)->actor,
            array_map(static fn (AddonTexts $texts): AddonTextsProp => new AddonTextsProp(
                $texts->addon,
                array_map(static fn (string $key, string $text): TextProp => new TextProp($key, $text), array_keys($texts->texts), array_values($texts->texts)),
            ), $active->texts),
        );
        $props = [self::CMS => [self::CONTRIBUTIONS => InertiaProps::document($this->codec->encode($prop, ClassificationAccess::Public))]];
        ksort($data, SORT_STRING);

        foreach ($data as $addon => $fills) {
            $props[self::DATA][$addon] = new DeferProp(fn (): array|stdClass => $this->documents($active->page, $fills, $credential, $run, $refuse), $addon);
        }

        return new SharedProps($props);
    }

    /**
     * The fill as the host gets it: what every contribution has, and what its kind needs besides;
     * the props are null for a point whose props the page holds in the browser.
     */
    private function fill(ActiveFill $active, ?JsonDocument $props): FillProp
    {
        $fill = $active->fill;
        $declaration = $fill->declaration;
        $command = $fill->command?->toString();

        return new FillProp(
            $fill->addon(),
            $active->data() instanceof CommandRef,
            $fill->contribution,
            $declaration->kind(),
            $fill->priority,
            $props,
            $declaration instanceof ActionContribution && $command !== null ? new ActionProp(
                $command,
                $declaration->label,
                $declaration->icon,
                array_map(static fn (string $property, string $pointer): PrefillProp => new PrefillProp($property, $pointer), array_keys($declaration->prefill), array_values($declaration->prefill)),
                $declaration->confirm,
                $declaration->tone,
            ) : null,
            $declaration instanceof FormCheck && $command !== null ? new CheckProp($command, $declaration->severity) : null,
            $declaration instanceof DecoratorContribution ? new DecoratorProp($declaration->tightens) : null,
            $declaration instanceof ReplacementContribution ? new ReplacementProp($declaration->key) : null,
            $declaration instanceof FlowStep && $command !== null ? new StepProp($command, $declaration->position, $declaration->patches, $declaration->timeoutSeconds) : null,
            $declaration instanceof NavContribution ? new NavProp($declaration->label, $declaration->icon, new ContributionId($declaration->page)) : null,
        );
    }

    /**
     * The pages a contribution may navigate to, sorted by page id: the panel's own pages (OwnPage),
     * each at its route, and every page of an addon the viewer may open, at
     * `<prefix>/x/<namespace>/<path>`.
     *
     * @return list<PageLinkProp>
     */
    private function pages(ActiveContributions $active): array
    {
        $pages = [];

        foreach (OwnPage::cases() as $own) {
            $pages[$own->value] = new PageLinkProp($own->value, rtrim($this->urls->route($own->route()->value, [], false), '/'));
        }

        foreach ($active->pages() as $page) {
            $declaration = $page->fill->declaration;

            if ($declaration instanceof PageContribution) {
                $pages[$declaration->id->value] = new PageLinkProp($declaration->id->value, $this->home().'/'.PanelRoute::ADDON_PAGES_PATH.'/'.$declaration->id->namespace()->value.'/'.$declaration->path);
            }
        }

        ksort($pages, SORT_STRING);

        return array_values($pages);
    }

    /**
     * The address of the panel's start page, without a trailing slash.
     */
    private function home(): string
    {
        return rtrim($this->urls->route(PanelRoute::Home->value, [], false), '/');
    }

    /**
     * The point's props for the fill, written by the point's codec at the fill's access, or null
     * when the point has no codec or the codec cannot write them.
     */
    private function encoded(PageName $page, ActiveFill $fill): ?JsonDocument
    {
        $codec = $this->points->find($fill->point);

        if ($fill->props === null) {
            return null;
        }

        if (! $codec instanceof PointCodec) {
            $this->telemetry->withheld($page, Withheld::PointWithoutCodec, $fill->point, $fill->fill->contribution);

            return null;
        }

        try {
            // The props are the point's, the class its codec writes, as ResolveContributions built
            // them; the codec's template is covariant, so its parameter reads never.
            // @phpstan-ignore argument.type (the props of this codec's point, see above)
            return new JsonDocument($codec->codec->encode($fill->props, $fill->access));
        } catch (EncodingFailed) {
            $this->telemetry->withheld($page, Withheld::PropsUnencodable, $fill->point, $fill->fill->contribution);

            return null;
        }
    }

    /**
     * The data of each fill whose query answered, by the fill's id; an object without members when
     * none did.
     *
     * @param  non-empty-list<EncodedFill>  $fills
     * @param  Closure(ContributionDataCall): ContributionData  $run
     * @param  Closure(PageName, ActiveFill, DataRefusal): ContributionData  $refuse
     * @return array<string, array<array-key, mixed>>|stdClass
     */
    private function documents(PageName $page, array $fills, TransportCredential $credential, Closure $run, Closure $refuse): array|stdClass
    {
        $documents = [];

        foreach ($fills as $encoded) {
            $fill = $encoded->fill;
            $query = $fill->data();
            $codec = $query instanceof CommandRef ? $this->queries->find($query->name, $query->version) : null;

            if (! $codec instanceof QueryCodec) {
                $refuse($page, $fill, DataRefusal::NoCodec);

                continue;
            }

            try {
                $input = $codec->query->decode(QueryInput::of($encoded->props, $codec->querySchema), ClassificationAccess::Public);
            } catch (DecodingFailed) {
                $refuse($page, $fill, DataRefusal::InputInvalid);

                continue;
            }

            $data = $run(new ContributionDataCall($page, $fill, $input, $credential));

            if ($data->failure instanceof Throwable) {
                $this->exceptions->report($data->failure);
            }

            if (! $data->result instanceof Result) {
                continue;
            }

            try {
                // The query pipeline answers with the result of the query the codec is registered
                // for, the class the codec writes; the codec's template is covariant, so its
                // parameter reads never.
                // @phpstan-ignore argument.type (the result of this codec's query, see above)
                $documents[$fill->fill->contribution->value] = InertiaProps::document($codec->result->encode($data->result, $data->access));
            } catch (EncodingFailed $failure) {
                $this->exceptions->report($failure);
            }
        }

        return $documents === [] ? new stdClass : $documents;
    }
}
