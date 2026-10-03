<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Contributions;

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\PanelPoints\PageName;
use Cbox\Cms\Contracts\PanelPoints\PointName;
use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Panel\Boundary\Generated\ContributionsCodecV1;
use Cbox\Cms\Panel\Boundary\PanelSessions;
use Cbox\Cms\Panel\Contributions\Boundary\ContributionProps;
use Cbox\Cms\Panel\Contributions\Boundary\SharedProps;
use Cbox\Cms\Panel\Contributions\Domain\ContributionTelemetry;
use Cbox\Cms\Panel\Contributions\Domain\DataRefusal;
use Cbox\Cms\Panel\Contributions\Domain\Dto\ActiveContributions;
use Cbox\Cms\Panel\Contributions\Domain\Dto\ActiveFill;
use Cbox\Cms\Panel\Contributions\Domain\Dto\ContributionData;
use Cbox\Cms\Panel\Contributions\Domain\Dto\ContributionDataCall;
use Cbox\Cms\Panel\Contributions\Domain\Dto\PanelView;
use Cbox\Cms\Panel\Contributions\Domain\Dto\RenderedPoint;
use Cbox\Cms\Panel\Contributions\Domain\Registrations;
use Cbox\Cms\Panel\Tests\Contributions\Fixtures\Desk\DeskAsideV1;
use Cbox\Cms\Panel\Tests\Contributions\Fixtures\Desk\DeskCardsV1;
use Cbox\Cms\Panel\Tests\Contributions\Fixtures\Tally\HeavyTally;
use Cbox\Cms\Panel\Tests\Contributions\Fixtures\Tally\TallyCodecs;
use Cbox\Cms\Panel\Tests\Contributions\Fixtures\Tally\TallyCount;
use Cbox\Cms\Panel\Tests\Contributions\Fixtures\Tally\TallyNotes;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use Inertia\DeferProp;
use LogicException;
use RuntimeException;
use stdClass;

/*
 * ContributionProps (PRD 13.4), the Boundary that turns a page's active contributions into its
 * props: cms.contributions written by its generated codec, each fill's props written by its point's
 * codec at the fill's access, and per addon a deferred prop that runs each fill's data query with
 * the input taken from those props by name. A point without a codec loses its contributions, and
 * a query without a codec or whose input the props do not give is refused, each in telemetry.
 */

function contributionProps(FakeTelemetry $telemetry, ?QueryCodecs $queries = null): ContributionProps
{
    return new ContributionProps(new ContributionsCodecV1, ContributionWorld::pointCodecs(), $queries ?? new QueryCodecs(...TallyCodecs::all()), new ContributionTelemetry($telemetry), app(ExceptionHandler::class), app(UrlGenerator::class));
}

function viewerRequest(): Request
{
    $request = Request::create('/cms/desk', 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer cms_sk_test']);
    $request->attributes->set(PanelSessions::PRINCIPAL, ResolveWorld::principal(ResolveWorld::AUDITOR));

    return $request;
}

function deskActive(string $note = 'Weekly desk', bool $aside = false): ActiveContributions
{
    $points = [new RenderedPoint(new PointName('desk.cards'), new DeskCardsV1($note, 'Call the printer'))];

    if ($aside) {
        $points[] = new RenderedPoint(new PointName('desk.aside'), new DeskAsideV1('Aside'));
    }

    return new ResolveWorld()->action()->resolve(new PanelView(new PageName(ContributionWorld::PAGE), ResolveWorld::principal(ResolveWorld::AUDITOR), $points));
}

/**
 * What the deferred prop of the addon resolves to.
 *
 * @param  array<string, mixed>  $props
 */
function deferredData(array $props): mixed
{
    $ext = $props[ContributionProps::DATA] ?? null;
    $prop = is_array($ext) ? $ext['tally'] ?? null : null;

    if (! $prop instanceof DeferProp) {
        throw new LogicException('No deferred prop for tally.');
    }

    return $prop();
}

it('writes cms.contributions with each fill s props at its access and leaves a point without a codec out, counting it', function (): void {
    $telemetry = new FakeTelemetry;
    $props = contributionProps($telemetry)->props(viewerRequest(), deskActive(aside: true), static fn (ContributionDataCall $call): ContributionData => ContributionData::answered(new TallyCount(3, 's', 'o'), $call->fill->access), static fn (PageName $page, ActiveFill $fill, DataRefusal $refusal): ContributionData => ContributionData::refused($refusal))->props;
    $cms = $props[ContributionProps::CMS] ?? null;
    $contributions = is_array($cms) ? $cms[ContributionProps::CONTRIBUTIONS] ?? null : null;
    $points = is_array($contributions) ? $contributions['points'] ?? null : null;

    expect(is_array($points) ? array_column($points, 'point') : null)->toBe(['desk.cards@1'])
        ->and($telemetry->counters())->toHaveCount(1)
        ->and(($telemetry->counters()[0] ?? null)?->attributes->get(ContributionTelemetry::REASON))->toBe('point_without_codec')
        ->and(($telemetry->counters()[0] ?? null)?->attributes->get(ContributionTelemetry::CONTRIBUTION))->toBe(ContributionWorld::ASIDE)
        ->and(deferredData($props))->toBe([
            ContributionWorld::COUNT => ['count' => 3, 'summary' => 's'],
            ContributionWorld::HEAVY => ['count' => 3, 'summary' => 's'],
        ]);
});

it('takes each query s input from the fill s props by name', function (): void {
    $calls = [];
    $props = contributionProps(new FakeTelemetry)->props(viewerRequest(), deskActive('Monday'), static function (ContributionDataCall $call) use (&$calls): ContributionData {
        $calls[] = $call;

        return ContributionData::rejected(ErrorCode::Unauthorized);
    }, static fn (PageName $page, ActiveFill $fill, DataRefusal $refusal): ContributionData => ContributionData::refused($refusal))->props;

    expect(deferredData($props))->toEqual(new stdClass)
        ->and(array_map(static fn (ContributionDataCall $call): string => $call->query instanceof TallyNotes ? 'notes:'.$call->query->note : $call->query::class, $calls))->toBe(['notes:Monday', HeavyTally::class])
        ->and($calls[0]->credential)->toBeInstanceOf(TransportCredential::class)
        ->and($calls[0]->fill->access)->toBe(ClassificationAccess::Internal);
});

it('refuses a query without a codec, and one whose input the props do not give, without running it', function (): void {
    $refused = [];
    $refuse = static function (PageName $page, ActiveFill $fill, DataRefusal $refusal) use (&$refused): ContributionData {
        $refused[] = [$fill->fill->contribution->value, $refusal];

        return ContributionData::refused($refusal);
    };
    $run = static fn (ContributionDataCall $call): ContributionData => throw new RuntimeException('It ran.');

    deferredData(contributionProps(new FakeTelemetry, new QueryCodecs)->props(viewerRequest(), deskActive(), $run, $refuse)->props);
    deferredData(contributionProps(new FakeTelemetry)->props(viewerRequest(), deskActive(''), $run, $refuse)->props);

    expect($refused)->toBe([
        [ContributionWorld::COUNT, DataRefusal::NoCodec],
        [ContributionWorld::HEAVY, DataRefusal::NoCodec],
        [ContributionWorld::COUNT, DataRefusal::InputInvalid],
        [ContributionWorld::HEAVY, DataRefusal::InputInvalid],
    ]);
});

it('sends contributions only for a request the panel authenticated', function (): void {
    $props = contributionProps(new FakeTelemetry);

    expect(fn (): PanelView => $props->view(Request::create('/cms'), ContributionWorld::PAGE))->toThrow(LogicException::class)
        ->and(fn (): SharedProps => $props->props(Request::create('/cms'), deskActive(), static fn (ContributionDataCall $call): ContributionData => ContributionData::refused(DataRefusal::NoCodec), static fn (PageName $page, ActiveFill $fill, DataRefusal $refusal): ContributionData => ContributionData::refused($refusal)))->toThrow(LogicException::class)
        ->and($props->view(viewerRequest(), ContributionWorld::PAGE)->viewer->actor->toString())->toBe(ResolveWorld::AUDITOR);
});

it('tells the host each point s kind and multiplicity, the registration of the addon, whether the viewer sees detail, the pages and where commands run', function (): void {
    $props = contributionProps(new FakeTelemetry)->props(viewerRequest(), deskActive(), static fn (ContributionDataCall $call): ContributionData => ContributionData::refused(DataRefusal::NoCodec), static fn (PageName $page, ActiveFill $fill, DataRefusal $refusal): ContributionData => ContributionData::refused($refusal))->props;
    $cms = $props[ContributionProps::CMS] ?? null;
    $contributions = json_decode((string) json_encode(is_array($cms) ? $cms[ContributionProps::CONTRIBUTIONS] ?? null : null), true);

    expect(is_array($contributions) ? $contributions : null)->toMatchArray([
        'addons' => [[
            'addon' => 'tally',
            'any_command' => false,
            'issues' => [],
            // Every contribution of the addon that runs code, the one the viewer does not get and
            // the one whose point has no codec included, and none of them by name.
            'registration' => Registrations::digest([ContributionWorld::ASIDE, ContributionWorld::AUDIT, ContributionWorld::COUNT, ContributionWorld::HEAVY]),
        ]],
        'commands' => '/cms/commands',
        'details' => true,
        'pages' => [['page' => 'home', 'url' => '/cms']],
    ])
        ->and(is_array($contributions) && is_array($contributions['points'] ?? null) ? array_map(static fn (mixed $point): array => is_array($point) ? array_intersect_key($point, ['kind' => true, 'max' => true, 'multiplicity' => true, 'region' => true]) : [], $contributions['points']) : null)->toBe([
            ['kind' => 'slot', 'max' => null, 'multiplicity' => 'many', 'region' => 'sections'],
        ])
        ->and(Registrations::digest(['b', 'a']))->toBe(hash('sha256', "a\nb"));
});
