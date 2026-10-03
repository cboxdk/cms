<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Codecs\JsonDocument;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\PageName;
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
use Cbox\Cms\Panel\Contributions\Domain\Dto\ContributionData;
use Cbox\Cms\Panel\Contributions\Domain\Dto\ContributionDataCall;
use Cbox\Cms\Panel\Contributions\Domain\Dto\PanelView;
use Cbox\Cms\Panel\Contributions\Domain\Dto\PointCodec;
use Cbox\Cms\Panel\Contributions\Domain\Dto\RenderedPoint;
use Cbox\Cms\Panel\Contributions\Domain\Dto\ViewSubject;
use Cbox\Cms\Panel\Contributions\Domain\PointCodecs;
use Cbox\Cms\Panel\Contributions\Domain\Withheld;
use Cbox\Cms\Panel\Domain\Dto\ContributionsProp;
use Cbox\Cms\Panel\Domain\Dto\FillProp;
use Cbox\Cms\Panel\Domain\Dto\PointFillsProp;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
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
 *   ResolveContributions works them out for the PanelView that view() reads from the request,
 *   written by the generated ContributionsCodecV1 (contributions.v1.json), each with the point's
 *   props as the point's codec (PointCodecs) wrote them at the fill's access, the lower of the
 *   viewer's and the addon's reads. A point without a codec, or props its codec cannot write, loses
 *   its contributions, recorded in telemetry (ContributionTelemetry::withheld());
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
    ) {}

    /**
     * The view of a page for the viewer the panel authenticated on the request.
     *
     * @param  list<RenderedPoint>  $points  the points the page renders, in its order
     *
     * @throws LogicException for a request the panel did not authenticate
     */
    public function view(Request $request, string $page, array $points = [], ViewSubject $subject = new ViewSubject): PanelView
    {
        $viewer = $request->attributes->get(PanelSessions::PRINCIPAL);

        if (! $viewer instanceof ActorPrincipal) {
            throw new LogicException(sprintf('The panel page %s shows contributions only for a request the panel authenticated.', $page));
        }

        return new PanelView(new PageName($page), $viewer, $points, $subject);
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
                $props = $this->encoded($active->page, $fill);

                if (! $props instanceof JsonDocument) {
                    continue;
                }

                $query = $fill->data();
                $fills[] = new FillProp($fill->fill->addon(), $query instanceof CommandRef, $fill->fill->contribution, $fill->fill->declaration->kind(), $fill->fill->priority, $props);

                if ($query instanceof CommandRef) {
                    $data[$fill->fill->addon()->value][] = new EncodedFill($fill, $props);
                }
            }

            if ($fills !== []) {
                $points[] = new PointFillsProp($point->point->toString(), $fills);
            }
        }

        $props = [self::CMS => [self::CONTRIBUTIONS => InertiaProps::document($this->codec->encode(new ContributionsProp($points), ClassificationAccess::Public))]];
        ksort($data, SORT_STRING);

        foreach ($data as $addon => $fills) {
            $props[self::DATA][$addon] = new DeferProp(fn (): array|stdClass => $this->documents($active->page, $fills, $credential, $run, $refuse), $addon);
        }

        return new SharedProps($props);
    }

    /**
     * The point's props for the fill, written by the point's codec at the fill's access, or null
     * when the point has no codec or the codec cannot write them.
     */
    private function encoded(PageName $page, ActiveFill $fill): ?JsonDocument
    {
        $codec = $this->points->find($fill->point);

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
