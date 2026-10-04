<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\PanelStories\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\ActionContribution;
use Cbox\Cms\Contracts\PanelPoints\DecoratorContribution;
use Cbox\Cms\Contracts\PanelPoints\FlowStep;
use Cbox\Cms\Contracts\PanelPoints\FormCheck;
use Cbox\Cms\Contracts\PanelPoints\NavContribution;
use Cbox\Cms\Contracts\PanelPoints\Region;
use Cbox\Cms\Contracts\PanelPoints\ReplacementContribution;
use Cbox\Cms\Contracts\PanelPoints\Tighten;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelFill;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\ArrayLiteral;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\BooleanLiteral;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\Literal;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\LiteralPrinter;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\NumberLiteral;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\ObjectLiteral;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\Property;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\Reference;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\StringLiteral;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationResult;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\PanelStories\Domain\Dto\StoryPoint;

/**
 * The stories of the panel's points (PRD 13.4, section 2.7 of the panel extension architecture),
 * the section "Panel points" of the Storybook gate 7 tests, generated from panel.php so that no
 * point exists without a story. DIRECTORY holds two modules, which cms:panel:stories owns:
 *
 * - DATA, `points.ts`: every point with its id, page, label, since, stability and props class,
 *   its props schema as JSON text, and the point as cms.contributions sends it, its kind, region
 *   and multiplicity with the contributions compiled for it in render order, each handed the
 *   point's sample props;
 * - STORIES, `PanelPoints.stories.tsx`: the overview of every point, and one story per point,
 *   which js/panel/stories/PanelPointStory.tsx renders with the point's host.
 *
 * Both are printed as Prettier prints them, sorted, without timestamps or paths.
 */
#[Internal]
final readonly class PanelStoriesModule
{
    /** Where the modules go, relative to the root of the repository; cms:panel:stories owns it. */
    public const string DIRECTORY = 'js/panel/stories/generated';

    public const string DATA = 'points.ts';

    public const string STORIES = 'PanelPoints.stories.tsx';

    /** The title of the section in Storybook. */
    public const string TITLE = 'Panel points';

    private function __construct() {}

    /**
     * @param  list<StoryPoint>  $points
     *
     * @throws GenerationFailed with generate_name_collision when two points give one story name
     */
    public static function result(array $points): GenerationResult
    {
        usort($points, static fn (StoryPoint $a, StoryPoint $b): int => [$a->declaration->name, $a->declaration->version] <=> [$b->declaration->name, $b->declaration->version]);

        return new GenerationResult([
            new GeneratedFile(self::DIRECTORY.'/'.self::STORIES, self::stories($points)),
            new GeneratedFile(self::DIRECTORY.'/'.self::DATA, self::data($points)),
        ], [self::DIRECTORY]);
    }

    /**
     * The name of a point's story: the segments of its name in TitleCase, then V and its version,
     * such as AccountMeSectionsV1 for account.me.sections@1.
     */
    public static function storyName(StoryPoint $point): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $point->declaration->name, -1, PREG_SPLIT_NO_EMPTY);

        return implode('', array_map(ucfirst(...), $words === false ? [] : $words)).'V'.$point->declaration->version;
    }

    /**
     * @param  list<StoryPoint>  $points
     *
     * @throws GenerationFailed
     */
    private static function stories(array $points): string
    {
        $names = [];
        $exports = [];

        foreach ($points as $point) {
            $name = self::storyName($point);
            $id = $point->declaration->id()->toString();

            if (isset($names[$name])) {
                throw GenerationFailed::because(GenerateErrorCode::NameCollision, sprintf('The panel points %s and %s both give the story %s. Rename one of the points.', $names[$name], $id, $name));
            }

            $names[$name] = $id;
            $exports[] = '';
            $exports[] = sprintf('/** %s, a %s point of the page %s. */', $id, $point->declaration->kind->value, $point->declaration->page);
            $exports[] = sprintf("export const %s: Story = pointStory(PANEL_POINTS, '%s');", $name, $id);
        }

        return implode("\n", [
            '// The section "Panel points" of the panel\'s Storybook (PRD 13.4, section 2.7 of the panel',
            '// extension architecture): the overview of every panel point of the installation, and a story',
            '// per point that renders its host with its sample props and the contributions compiled for it.',
            '//',
            '// Generated by cms:panel:stories from panel.php, which cms:build compiles.',
            '// Do not edit this file: declare a point with #[PanelPoint], then run cms:build and cms:panel:stories.',
            '',
            $points === []
                ? "import { overviewStory, PanelPointStory, type Story } from '../PanelPointStory';"
                : "import { overviewStory, PanelPointStory, pointStory, type Story } from '../PanelPointStory';",
            "import { PANEL_POINTS } from './points';",
            '',
            'const meta = {',
            "  title: '".self::TITLE."',",
            '  component: PanelPointStory,',
            '};',
            '',
            'export default meta;',
            '',
            '/** Every panel point of the installation, with its kind, stability and contributions. */',
            'export const Overview: Story = overviewStory(PANEL_POINTS);',
            ...$exports,
            '',
        ]);
    }

    /**
     * @param  list<StoryPoint>  $points
     */
    private static function data(array $points): string
    {
        return implode("\n", [
            '// The panel points of the installation as their stories show them: each with its id, page, label,',
            '// since, stability and props class, its props schema, and the point as cms.contributions sends it',
            '// with the contributions cms:build compiled for it, each handed the point\'s sample props.',
            '//',
            '// Generated by cms:panel:stories from panel.php, which cms:build compiles.',
            '// Do not edit this file: declare a point with #[PanelPoint], then run cms:build and cms:panel:stories.',
            '',
            "import type { PanelPointStoryData } from '../PanelPointStory';",
            '',
            ...LiteralPrinter::constant('PANEL_POINTS', 'readonly PanelPointStoryData[]', new ArrayLiteral(array_map(self::point(...), $points))),
            '',
            'export { PANEL_POINTS };',
            '',
        ]);
    }

    private static function point(StoryPoint $point): ObjectLiteral
    {
        $declaration = $point->declaration;
        $sample = $point->sample ?? new ObjectLiteral([]);

        return new ObjectLiteral([
            new Property('id', new StringLiteral($declaration->id()->toString())),
            new Property('page', new StringLiteral($declaration->page)),
            new Property('label', new StringLiteral($declaration->label)),
            new Property('since', new StringLiteral($declaration->since)),
            new Property('stability', new StringLiteral($point->stability)),
            new Property('class', new StringLiteral($point->class)),
            new Property('schema', $point->schema === null ? new Reference('null') : new StringLiteral($point->schema)),
            new Property('point', new ObjectLiteral([
                new Property('fills', new ArrayLiteral(array_map(static fn (PanelFill $fill): ObjectLiteral => self::fill($fill, $sample), $point->fills))),
                new Property('kind', new StringLiteral($declaration->kind->value)),
                new Property('max', $declaration->max === null ? new Reference('null') : NumberLiteral::of($declaration->max)),
                new Property('multiplicity', new StringLiteral($declaration->multiplicity->value)),
                new Property('point', new StringLiteral($declaration->id()->toString())),
                new Property('region', $declaration->region instanceof Region ? new StringLiteral($declaration->region->value) : new Reference('null')),
            ])),
        ]);
    }

    /**
     * A contribution as cms.contributions sends it (contributions.v1.json, `#/$defs/fill`), handed
     * the sample props.
     */
    private static function fill(PanelFill $fill, Literal $sample): ObjectLiteral
    {
        $declaration = $fill->declaration;
        $command = $fill->command?->toString();
        $null = new Reference('null');

        return new ObjectLiteral([
            new Property('action', $declaration instanceof ActionContribution && $command !== null ? new ObjectLiteral([
                new Property('command', new StringLiteral($command)),
                new Property('confirm', new StringLiteral($declaration->confirm->value)),
                new Property('icon', $declaration->icon === null ? $null : new StringLiteral($declaration->icon)),
                new Property('label', new StringLiteral($declaration->label)),
                new Property('prefill', new ArrayLiteral(array_map(
                    static fn (string $property, string $pointer): ObjectLiteral => new ObjectLiteral([new Property('pointer', new StringLiteral($pointer)), new Property('property', new StringLiteral($property))]),
                    array_keys($declaration->prefill),
                    array_values($declaration->prefill),
                ))),
                new Property('tone', new StringLiteral($declaration->tone->value)),
            ]) : $null),
            new Property('addon', new StringLiteral($fill->addon()->value)),
            new Property('check', $declaration instanceof FormCheck && $command !== null ? new ObjectLiteral([
                new Property('command', new StringLiteral($command)),
                new Property('severity', new StringLiteral($declaration->severity->value)),
            ]) : $null),
            new Property('data', new BooleanLiteral(false)),
            new Property('decorator', $declaration instanceof DecoratorContribution ? new ObjectLiteral([
                new Property('tightens', ArrayLiteral::strings(array_map(static fn (Tighten $tighten): string => $tighten->value, $declaration->tightens))),
            ]) : $null),
            new Property('id', new StringLiteral($fill->contribution->value)),
            new Property('kind', new StringLiteral($declaration->kind()->value)),
            new Property('nav', $declaration instanceof NavContribution ? new ObjectLiteral([
                new Property('icon', $declaration->icon === null ? $null : new StringLiteral($declaration->icon)),
                new Property('label', new StringLiteral($declaration->label)),
                new Property('page', new StringLiteral($declaration->page)),
            ]) : $null),
            new Property('priority', NumberLiteral::of($fill->priority)),
            new Property('props', $sample),
            new Property('replacement', $declaration instanceof ReplacementContribution ? new ObjectLiteral([new Property('key', new StringLiteral($declaration->key))]) : $null),
            new Property('step', $declaration instanceof FlowStep && $command !== null ? new ObjectLiteral([
                new Property('command', new StringLiteral($command)),
                new Property('patches', ArrayLiteral::strings($declaration->patches)),
                new Property('position', new StringLiteral($declaration->position->value)),
                new Property('timeout_seconds', NumberLiteral::of($declaration->timeoutSeconds)),
            ]) : $null),
        ]);
    }
}
