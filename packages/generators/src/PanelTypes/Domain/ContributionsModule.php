<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\PanelTypes\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationResult;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\PanelTypes\Domain\Dto\AddonUi;
use Cbox\Cms\Generators\PanelTypes\Domain\Dto\ContractShape;
use Cbox\Cms\Generators\PanelTypes\Domain\Dto\TypeExpression;
use Cbox\Cms\Generators\PanelTypes\Domain\Dto\UiContribution;

/**
 * The module cms:panel:types writes for an addon (PRD 13.4, section 3.1 of the panel extension
 * architecture), DIRECTORY/contributions.ts below the addon's package:
 *
 * - Contributions: an interface with a member per contribution of the addon's manifest that runs
 *   code, by its id, of the type its kind takes, on its point's props and its data query's result
 *   or its form's command document, which definePanelAddon<Contributions>() takes, so tsc in the
 *   addon's repository refuses a missing key, an extra key and wrong props;
 * - Issues: an interface with a member per command the addon's UI may issue, by its name and
 *   version, of its document, which usePanelHost<Issues>() takes, so runCommand() takes only
 *   those;
 * - the types of the data queries' results and of the commands' documents, from their JSON
 *   Schemas, named after the query or command, such as ReviewsPendingResultV1 and
 *   ReviewsRequestV1.
 *
 * The points' props come from the SDK: a stable point's from @cboxdk/cms-panel/extend and an
 * experimental point's from @cboxdk/cms-panel/experimental, so the import says the addon relies on
 * experimental API. The module is a function of the registry and the schemas: sorted, without
 * timestamps or paths, laid out as Prettier prints it.
 */
#[Internal]
final readonly class ContributionsModule
{
    /** The directory of the module, below the addon's package; cms:panel:types owns it. */
    public const string DIRECTORY = 'resources/panel/generated';

    public const string FILE = 'contributions.ts';

    public const string EXTEND = '@cboxdk/cms-panel/extend';

    public const string EXPERIMENTAL = '@cboxdk/cms-panel/experimental';

    /**
     * The names the module declares itself.
     *
     * @var list<string>
     */
    private const array OWN_NAMES = ['Contributions', 'Issues'];

    /**
     * @throws GenerationFailed with generate_name_collision or generate_schema_invalid
     */
    public static function result(AddonUi $addon): GenerationResult
    {
        return new GenerationResult(
            [new GeneratedFile(self::DIRECTORY.'/'.self::FILE, self::source($addon))],
            [self::DIRECTORY],
        );
    }

    /**
     * @throws GenerationFailed with generate_name_collision or generate_schema_invalid
     */
    public static function source(AddonUi $addon): string
    {
        $namespace = $addon->namespace->value;
        $extend = [];
        $experimental = [];
        $declared = [];
        $documents = [];
        $contracts = [];

        foreach ($addon->contributions as $contribution) {
            foreach ([$contribution->data, $contribution->command] as $contract) {
                if ($contract instanceof ContractShape) {
                    $contracts[$contract->ref->toString().($contract === $contribution->data ? '#result' : '')] = [$contract, $contract === $contribution->data];
                }
            }
        }

        foreach ($addon->issues as $issue) {
            $contracts[$issue->ref->toString()] = [$issue, false];
        }

        ksort($contracts, SORT_STRING);
        $names = [];

        foreach ($contracts as $key => [$contract, $result]) {
            $declarations = ShapeDeclarations::of(
                $contract->document,
                ShapeDeclarations::pascal($contract->ref->name->value).($result ? 'Result' : ''),
                'V'.$contract->ref->version,
                $result
                    ? sprintf('The result of the data query %s, as its result codec writes it.', $contract->ref->toString())
                    : sprintf('The document of the command %s, as its codec reads it.', $contract->ref->toString()),
            );
            $names[$key] = $declarations->name;
            $documents = [...$documents, ...$declarations->lines];

            foreach ($declarations->imports as $import) {
                $extend[$import] = true;
            }

            foreach (self::declaredNames($declarations->lines) as $name) {
                $declared[] = $name;
            }
        }

        $members = [];

        $points = [];

        foreach ($addon->contributions as $contribution) {
            if (self::usesProps($contribution->kind)) {
                $points[$contribution->point->name] = true;
            }
        }

        $declared = [...$declared, ...array_keys($points)];

        foreach ($addon->contributions as $contribution) {
            $props = $contribution->point->name;

            if (! self::usesProps($contribution->kind)) {
                // A form check and a flow step are typed on the command's document, not the point's props.
            } elseif ($contribution->point->stable) {
                $extend[$props] = true;
            } else {
                $experimental[$props] = true;
            }

            $type = self::contributionType($contribution, $names);

            foreach (self::uses($contribution->kind) as $use) {
                $extend[$use] = true;
            }

            $members = [
                ...$members,
                ...TypeScriptLayout::comment(self::describe($contribution), 2),
                ...TypeScriptLayout::statement('readonly '.TypeScriptLayout::key($contribution->id->value).':', $type, 2),
            ];
        }

        $issues = [];

        foreach ($addon->issues as $issue) {
            $issues = [
                ...$issues,
                ...TypeScriptLayout::statement('readonly '.TypeScriptLayout::key($issue->ref->toString()).':', TypeExpression::atom($names[$issue->ref->toString()] ?? ''), 2),
            ];
        }

        if ($members === []) {
            $extend['NoContributions'] = true;
        }

        if ($issues === []) {
            $extend['NoCommands'] = true;
        }

        self::assertDistinct([...self::OWN_NAMES, ...$declared], $namespace);

        $imports = [];

        foreach ([self::EXTEND => $extend, self::EXPERIMENTAL => $experimental] as $module => $used) {
            if ($used !== []) {
                $imports = [...$imports, ...TypeScriptLayout::import(array_keys($used), $module)];
            }
        }

        $lines = [
            sprintf('// The panel contributions of the addon %s as TypeScript (PRD 13.4): Contributions, what', $namespace),
            '// definePanelAddon<Contributions>() takes, Issues, the commands usePanelHost<Issues>() may',
            '// issue, and the documents they exchange, from the JSON Schemas of their codecs.',
            '//',
            sprintf('// Generated by cms:panel:types %s from the contributions cms:build compiled from the', $namespace),
            "// addon's manifest. Do not edit this file: change the manifest, run cms:build and then",
            sprintf('// cms:panel:types %s.', $namespace),
            '',
            ...$imports,
            ...$documents,
            '',
            ...TypeScriptLayout::comment(sprintf('The contributions of %s that run code, by id: what definePanelAddon<Contributions>() takes.', $namespace), 0),
            ...($members === [] ? ['export type Contributions = NoContributions;'] : ['export interface Contributions {', ...$members, '}']),
            '',
            ...TypeScriptLayout::comment(sprintf('The commands %s may issue, by name and version: what usePanelHost<Issues>() takes.', $namespace), 0),
            ...($issues === [] ? ['export type Issues = NoCommands;'] : ['export interface Issues {', ...$issues, '}']),
            '',
        ];

        return implode("\n", $lines);
    }

    /**
     * Whether the member of a contribution of the kind is typed on the point's props, so the
     * module imports them: a page is typed on its data alone, and a form check and a flow step on
     * the command's document, so their props type would be an unused import, which tsc refuses.
     */
    private static function usesProps(PointKind $kind): bool
    {
        return match ($kind) {
            PointKind::Slot, PointKind::Decorator, PointKind::Replacement, PointKind::Observer, PointKind::Provider => true,
            default => false,
        };
    }

    /**
     * The SDK's names the member of a contribution of the kind uses.
     *
     * @return list<string>
     */
    private static function uses(PointKind $kind): array
    {
        return match ($kind) {
            PointKind::Slot => ['Lazy', 'SlotComponent'],
            PointKind::Page => ['Lazy', 'PageComponent'],
            PointKind::Decorator => ['Decorator'],
            PointKind::Replacement => ['Lazy', 'Replacement'],
            PointKind::FormCheck => ['FormCheck'],
            PointKind::FlowStep => ['FlowStep', 'Lazy'],
            PointKind::Observer => ['Observer'],
            PointKind::Provider => ['Lazy', 'Provider'],
            default => [],
        };
    }

    /**
     * The type of a contribution's member of Contributions.
     *
     * @param  array<string, string>  $names  the type of each contract's document, by its key
     *
     * @throws GenerationFailed with generate_invalid_output for a kind that runs no code, or a form contribution without its command
     */
    private static function contributionType(UiContribution $contribution, array $names): TypeExpression
    {
        $props = TypeExpression::atom($contribution->point->name);
        $data = $contribution->data instanceof ContractShape ? TypeExpression::atom($names[$contribution->data->ref->toString().'#result'] ?? '') : null;
        $document = $contribution->command instanceof ContractShape ? TypeExpression::atom($names[$contribution->command->ref->toString()] ?? '') : null;

        return match ($contribution->kind) {
            PointKind::Slot => self::lazy(TypeExpression::generic('SlotComponent', $data instanceof TypeExpression ? [$props, $data] : [$props])),
            PointKind::Page => self::lazy($data instanceof TypeExpression ? TypeExpression::generic('PageComponent', [$data]) : TypeExpression::atom('PageComponent')),
            PointKind::Decorator => TypeExpression::generic('Decorator', $contribution->tightens === [] ? [$props] : [$props, self::literals($contribution->tightens)]),
            PointKind::Replacement => self::lazy(TypeExpression::generic('Replacement', [$props])),
            PointKind::FormCheck => TypeExpression::generic('FormCheck', [self::document($contribution, $document)]),
            PointKind::FlowStep => self::lazy(TypeExpression::generic('FlowStep', [
                self::document($contribution, $document),
                $contribution->patches === [] ? TypeExpression::atom('never') : self::literals($contribution->patches),
                TypeExpression::atom('Issues'),
            ])),
            PointKind::Observer => TypeExpression::generic('Observer', [$props]),
            PointKind::Provider => self::lazy(TypeExpression::generic('Provider', [$props])),
            default => throw GenerationFailed::because(GenerateErrorCode::InvalidOutput, sprintf(
                'The contribution %s is of the kind %s, which runs no code, so it has no member of Contributions.',
                $contribution->id->value,
                $contribution->kind->value,
            )),
        };
    }

    private static function lazy(TypeExpression $type): TypeExpression
    {
        return TypeExpression::generic('Lazy', [$type]);
    }

    /**
     * @param  list<string>  $values
     */
    private static function literals(array $values): TypeExpression
    {
        sort($values, SORT_STRING);

        return TypeExpression::union(array_map(
            static fn (string $value): TypeExpression => TypeExpression::atom("'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'"),
            $values,
        ));
    }

    /**
     * @throws GenerationFailed with generate_invalid_output for a form contribution without its command
     */
    private static function document(UiContribution $contribution, ?TypeExpression $document): TypeExpression
    {
        if (! $document instanceof TypeExpression) {
            throw GenerationFailed::because(GenerateErrorCode::InvalidOutput, sprintf(
                'The %s %s names no command whose document it reads; cms:build resolves it from the manifest.',
                $contribution->kind->value,
                $contribution->id->value,
            ));
        }

        return $document;
    }

    private static function describe(UiContribution $contribution): string
    {
        $kind = str_replace('_', ' ', $contribution->kind->value);
        $text = sprintf('%s %s at %s', in_array($kind[0], ['a', 'e', 'i', 'o', 'u'], true) ? 'An' : 'A', $kind, $contribution->point->point->toString());

        if ($contribution->data instanceof ContractShape) {
            $text .= ', with the result of '.$contribution->data->ref->toString();
        }

        if ($contribution->command instanceof ContractShape) {
            $text .= ', on the form of '.$contribution->command->ref->toString();
        }

        return $text.'.';
    }

    /**
     * The names the lines declare.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private static function declaredNames(array $lines): array
    {
        $names = [];

        foreach ($lines as $line) {
            if (preg_match('/\Aexport (?:interface|type) ([A-Za-z_$][A-Za-z0-9_$]*)/', $line, $match) === 1) {
                $names[] = $match[1];
            }
        }

        return $names;
    }

    /**
     * @param  list<string>  $names
     *
     * @throws GenerationFailed with generate_name_collision
     */
    private static function assertDistinct(array $names, string $namespace): void
    {
        $seen = [];

        foreach ($names as $name) {
            $seen[$name] = ($seen[$name] ?? 0) + 1;
        }

        foreach ($seen as $name => $count) {
            if ($count > 1) {
                throw GenerationFailed::because(GenerateErrorCode::NameCollision, sprintf(
                    'The panel types of %s name %s twice: two of its data queries, commands or points give one TypeScript name, or one is called Contributions or Issues. Rename the query or command.',
                    $namespace,
                    $name,
                ));
            }
        }
    }
}
