<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Codecs;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Codecs\JsonDocument;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\PanelPoints\Confirm;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\Multiplicity;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Region;
use Cbox\Cms\Contracts\PanelPoints\Severity;
use Cbox\Cms\Contracts\PanelPoints\StepPosition;
use Cbox\Cms\Contracts\PanelPoints\Tighten;
use Cbox\Cms\Contracts\PanelPoints\Tone;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Tests\Codecs\KernelSchema;
use Cbox\Cms\Panel\Boundary\Generated\ContributionsCodecV1;
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
use Cbox\Cms\Tests\Support\TypeScript\TypeScriptValidators;
use Cbox\Cms\Tooling\Protocol\Domain\PanelPageSchemas;

/*
 * The prop cms.contributions of the panel's pages behind the login (GUARDRAILS 2.2, PRD 13.4),
 * contributions.v1.json: what its generated PHP codec writes validates against the schema with an
 * independent validator, opis/json-schema, and with the generated TypeScript validator the host
 * imports, and reads back to the same JSON; the props of each fill are embedded as the point's
 * codec wrote them, and each kind's descriptor is written beside them, a nav entry's included,
 * with the addons' pages among the pages. A kind the schema does not list is refused by all three.
 */

const CONTRIBUTIONS_SCHEMA = 'contributions.v1.json';

function contributionsJson(): string
{
    return new ContributionsCodecV1()->encode(new ContributionsProp(
        [
            new PointFillsProp('desk.cards@1', [
                new FillProp(new AddonNamespace('tally'), false, new ContributionId('tally.audit'), PointKind::Slot, 10, new JsonDocument('{"note":"Weekly desk"}')),
                new FillProp(new AddonNamespace('tally'), true, new ContributionId('tally.count'), PointKind::Slot, 20, new JsonDocument('{"memo":"Call","note":"Weekly desk","tags":{}}')),
            ], PointKind::Slot, Region::Sections, Multiplicity::Many, null),
            new PointFillsProp('desk.actions@1', [
                new FillProp(new AddonNamespace('tally'), false, new ContributionId('tally.recount'), PointKind::Action, 30, new JsonDocument('{}'), action: new ActionProp('tally.recount@1', 'tally.recount.label', 'refresh', [new PrefillProp('note', '/note')], Confirm::DryRun, Tone::Warning)),
            ], PointKind::Action, null, Multiplicity::Max, 3),
            new PointFillsProp('desk.form.submit@1', [
                new FillProp(new AddonNamespace('tally'), false, new ContributionId('tally.guard'), PointKind::Decorator, 40, new JsonDocument('{}'), decorator: new DecoratorProp([Tighten::Description, Tighten::ToneTowardsDanger])),
            ], PointKind::Decorator, null, Multiplicity::Many, null),
            new PointFillsProp('desk.form.checks@1', [
                new FillProp(new AddonNamespace('tally'), false, new ContributionId('tally.hint'), PointKind::FormCheck, 50, new JsonDocument('{}'), check: new CheckProp('tally.recount@1', Severity::Acknowledge)),
            ], PointKind::FormCheck, null, Multiplicity::Many, null),
            new PointFillsProp('desk.form.steps@1', [
                new FillProp(new AddonNamespace('tally'), false, new ContributionId('tally.reason'), PointKind::FlowStep, 60, new JsonDocument('{}'), step: new StepProp('tally.recount@1', StepPosition::BeforeSubmit, ['fields.ext.tally.reason'], 20)),
            ], PointKind::FlowStep, null, Multiplicity::Many, null),
            new PointFillsProp('desk.form.field@1', [
                new FillProp(new AddonNamespace('tally'), false, new ContributionId('tally.stars'), PointKind::Replacement, 70, new JsonDocument('{}'), replacement: new ReplacementProp('tally:stars')),
            ], PointKind::Replacement, null, Multiplicity::Exclusive, null),
            new PointFillsProp('shell.nav@1', [
                new FillProp(new AddonNamespace('tally'), false, new ContributionId('tally.board-link'), PointKind::Nav, 80, new JsonDocument('{}'), nav: new NavProp('tally.nav.board', 'inbox', new ContributionId('tally.board'))),
            ], PointKind::Nav, null, Multiplicity::Many, null),
            new PointFillsProp('shell.page@1', [
                new FillProp(new AddonNamespace('tally'), true, new ContributionId('tally.board'), PointKind::Page, 90, new JsonDocument('{}')),
            ], PointKind::Page, null, Multiplicity::Many, null),
        ],
        [new AddonProp(new AddonNamespace('tally'), str_repeat('a', 64), ['tally.recount@1'], false)],
        '/cms/commands',
        true,
        [new PageLinkProp('home', '/cms'), new PageLinkProp('tally.board', '/cms/x/tally/board')],
        ActorId::fromString('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01'),
        [new AddonTextsProp(new AddonNamespace('tally'), [
            new TextProp('tally.nav.board', 'Board'),
            new TextProp('tally.recount.label', 'Count again'),
        ])],
    ), ClassificationAccess::Public);
}

it('writes JSON valid against its schema and the TypeScript validator, and reads it back to the same JSON', function (): void {
    $json = contributionsJson();
    $codec = new ContributionsCodecV1;

    expect($json)->toStartWith('{"addons":[{"addon":"tally","any_command":false,"issues":["tally.recount@1"],"registration":"'.str_repeat('a', 64).'"}],"commands":"/cms/commands","details":true,"pages":[{"page":"home","url":"/cms"},{"page":"tally.board","url":"/cms/x/tally/board"}],"points":[{"fills":[{"action":null,"addon":"tally","check":null,"data":false,"decorator":null,"id":"tally.audit","kind":"slot","nav":null,"priority":10,"props":{"note":"Weekly desk"},"replacement":null,"step":null},')
        ->and($json)->toContain('"action":{"command":"tally.recount@1","confirm":"dry_run","icon":"refresh","label":"tally.recount.label","prefill":[{"pointer":"/note","property":"note"}],"tone":"warning"}')
        ->and($json)->toContain('"kind":"action","max":3,"multiplicity":"max","point":"desk.actions@1","region":null}')
        ->and($json)->toContain('"step":{"command":"tally.recount@1","patches":["fields.ext.tally.reason"],"position":"before_submit","timeout_seconds":20}')
        ->and($json)->toContain('"kind":"nav","nav":{"icon":"inbox","label":"tally.nav.board","page":"tally.board"},"priority":80')
        ->and($json)->toContain('"pages":[{"page":"home","url":"/cms"},{"page":"tally.board","url":"/cms/x/tally/board"}]')
        // The texts of the active locale per addon, which the host serves a contribution's t()
        // from (section 2.6 of the panel extension architecture).
        ->and($json)->toContain('"texts":[{"addon":"tally","entries":[{"key":"tally.nav.board","text":"Board"},{"key":"tally.recount.label","text":"Count again"}]}]')
        ->and(KernelSchema::errors(CONTRIBUTIONS_SCHEMA, $json, PanelPageSchemas::SCHEMA_DIRECTORY))->toBe([])
        ->and($codec->encode($codec->decode($json, ClassificationAccess::Public), ClassificationAccess::Public))->toBe($json)
        ->and(TypeScriptValidators::run(PanelPageSchemas::TYPESCRIPT_DIRECTORY, [
            ['module' => 'pages/ContributionsV1', 'validator' => 'validateContributionsV1', 'document' => $json],
            ['module' => 'pages/ContributionsV1', 'validator' => 'validateContributionsV1', 'document' => '{"addons":[],"commands":"/cms/commands","details":false,"pages":[],"points":[],"texts":[],"viewer":null}'],
        ]))->toBe([['valid' => true], ['valid' => true]]);
});

it('refuses a kind the schema does not list, in PHP, in TypeScript and in the schema', function (): void {
    $planted = str_replace('"kind":"slot","nav":null,"priority":10', '"kind":"widget","nav":null,"priority":10', contributionsJson());

    expect($planted)->not->toBe(contributionsJson());

    expect(fn (): ContributionsProp => new ContributionsCodecV1()->decode($planted, ClassificationAccess::Public))->toThrow(DecodingFailed::class)
        ->and(KernelSchema::errors(CONTRIBUTIONS_SCHEMA, $planted, PanelPageSchemas::SCHEMA_DIRECTORY))->not->toBe([])
        ->and(TypeScriptValidators::run(PanelPageSchemas::TYPESCRIPT_DIRECTORY, [
            ['module' => 'pages/ContributionsV1', 'validator' => 'validateContributionsV1', 'document' => $planted],
        ])[0]['valid'] ?? null)->toBeFalse();
});
