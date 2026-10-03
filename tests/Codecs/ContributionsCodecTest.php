<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Codecs;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Codecs\JsonDocument;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Tests\Codecs\KernelSchema;
use Cbox\Cms\Panel\Boundary\Generated\ContributionsCodecV1;
use Cbox\Cms\Panel\Domain\Dto\ContributionsProp;
use Cbox\Cms\Panel\Domain\Dto\FillProp;
use Cbox\Cms\Panel\Domain\Dto\PointFillsProp;
use Cbox\Cms\Tests\Support\TypeScript\TypeScriptValidators;
use Cbox\Cms\Tooling\Protocol\Domain\PanelPageSchemas;

/*
 * The prop cms.contributions of the panel's pages behind the login (GUARDRAILS 2.2, PRD 13.4),
 * contributions.v1.json: what its generated PHP codec writes validates against the schema with an
 * independent validator, opis/json-schema, and with the generated TypeScript validator the host
 * imports, and reads back to the same JSON; the props of each fill are embedded as the point's
 * codec wrote them. A kind the schema does not list is refused by all three.
 */

const CONTRIBUTIONS_SCHEMA = 'contributions.v1.json';

function contributionsJson(): string
{
    return new ContributionsCodecV1()->encode(new ContributionsProp([
        new PointFillsProp('desk.cards@1', [
            new FillProp(new AddonNamespace('tally'), false, new ContributionId('tally.audit'), PointKind::Slot, 10, new JsonDocument('{"note":"Weekly desk"}')),
            new FillProp(new AddonNamespace('tally'), true, new ContributionId('tally.count'), PointKind::Slot, 20, new JsonDocument('{"memo":"Call","note":"Weekly desk","tags":{}}')),
        ]),
    ]), ClassificationAccess::Public);
}

it('writes JSON valid against its schema and the TypeScript validator, and reads it back to the same JSON', function (): void {
    $json = contributionsJson();
    $codec = new ContributionsCodecV1;

    expect($json)->toBe('{"points":[{"fills":[{"addon":"tally","data":false,"id":"tally.audit","kind":"slot","priority":10,"props":{"note":"Weekly desk"}},{"addon":"tally","data":true,"id":"tally.count","kind":"slot","priority":20,"props":{"memo":"Call","note":"Weekly desk","tags":{}}}],"point":"desk.cards@1"}]}')
        ->and(KernelSchema::errors(CONTRIBUTIONS_SCHEMA, $json, PanelPageSchemas::SCHEMA_DIRECTORY))->toBe([])
        ->and($codec->encode($codec->decode($json, ClassificationAccess::Public), ClassificationAccess::Public))->toBe($json)
        ->and(TypeScriptValidators::run(PanelPageSchemas::TYPESCRIPT_DIRECTORY, [
            ['module' => 'pages/ContributionsV1', 'validator' => 'validateContributionsV1', 'document' => $json],
            ['module' => 'pages/ContributionsV1', 'validator' => 'validateContributionsV1', 'document' => '{"points":[]}'],
        ]))->toBe([['valid' => true], ['valid' => true]]);
});

it('refuses a kind the schema does not list, in PHP, in TypeScript and in the schema', function (): void {
    $planted = str_replace('"kind":"slot","priority":10', '"kind":"widget","priority":10', contributionsJson());

    expect(fn (): ContributionsProp => new ContributionsCodecV1()->decode($planted, ClassificationAccess::Public))->toThrow(DecodingFailed::class)
        ->and(KernelSchema::errors(CONTRIBUTIONS_SCHEMA, $planted, PanelPageSchemas::SCHEMA_DIRECTORY))->not->toBe([])
        ->and(TypeScriptValidators::run(PanelPageSchemas::TYPESCRIPT_DIRECTORY, [
            ['module' => 'pages/ContributionsV1', 'validator' => 'validateContributionsV1', 'document' => $planted],
        ])[0]['valid'] ?? null)->toBeFalse();
});
