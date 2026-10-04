<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Scaffold\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\Severity;
use Cbox\Cms\Contracts\PanelPoints\StepPosition;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\Literal;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\LiteralPrinter;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\PanelTypes\Domain\ContributionsModule;
use Cbox\Cms\Generators\Scaffold\Domain\Dto\RegistrationEntry;
use Cbox\Cms\Generators\Scaffold\Domain\Dto\StubContribution;
use Cbox\Cms\Generators\Scaffold\Domain\Dto\StubPoint;

/**
 * The stub of one contribution (PRD 13.4, section 7 of the panel extension architecture): the
 * module of its kind below resources/panel/src, typed on the point's props and the generated
 * document types, a test of its kind's conformance helper from @cboxdk/cms-panel/testing with
 * sample documents from the schemas, its entry in the registration, and the line of the manifest
 * that declares it, for a contribution cms:build has not compiled yet. An action runs no code, so
 * it has only the manifest line. Every file is laid out as Prettier prints it.
 */
#[Internal]
final readonly class ContributionStub
{
    private function __construct() {}

    /**
     * The files of the stub, relative to the package: the module and its test, or none for an
     * action.
     *
     * @return list<GeneratedFile>
     */
    public static function files(StubContribution $contribution): array
    {
        return match ($contribution->kind) {
            ScaffoldKind::Fill => [self::fill($contribution), self::fillTest($contribution)],
            ScaffoldKind::Check => [self::check($contribution), self::checkTest($contribution)],
            ScaffoldKind::Step => [self::step($contribution), self::stepTest($contribution)],
            ScaffoldKind::Action => [],
        };
    }

    /**
     * The contribution's entry in the registration, or null for an action.
     */
    public static function registration(StubContribution $contribution): ?RegistrationEntry
    {
        $id = $contribution->id;
        $module = ScaffoldNames::module($id);

        return match ($contribution->kind) {
            ScaffoldKind::Fill => new RegistrationEntry($id->value, sprintf("() => import('./%s')", $module)),
            ScaffoldKind::Step => new RegistrationEntry($id->value, sprintf("() => import('./%sStep')", $module)),
            ScaffoldKind::Check => new RegistrationEntry(
                $id->value,
                ScaffoldNames::variable($id).'Check',
                sprintf("import { %sCheck } from './%sCheck';", ScaffoldNames::variable($id), $module),
            ),
            ScaffoldKind::Action => null,
        };
    }

    /**
     * The line of the manifest's contributions that declares the contribution, with the imports
     * it needs, for the service provider's PanelContributions.
     */
    public static function manifestLine(StubContribution $contribution): string
    {
        $id = sprintf("new ContributionId('%s')", $contribution->id->value);
        $point = sprintf("'%s'", $contribution->point->id->toString());
        $command = $contribution->command instanceof CommandRef ? sprintf("'%s'", $contribution->command->toString()) : "''";

        return match ($contribution->kind) {
            ScaffoldKind::Fill => $contribution->query instanceof CommandRef
                ? sprintf('new SlotFill(%s, %s, data: <the class of the query %s>::class)', $id, $point, $contribution->query->toString())
                : sprintf('new SlotFill(%s, %s)', $id, $point),
            ScaffoldKind::Action => sprintf("new ActionContribution(%s, %s, %s, '%s.label')", $id, $point, $command, $contribution->id->value),
            ScaffoldKind::Check => sprintf('new FormCheck(%s, %s, %s, Severity::%s)', $id, $point, $command, self::severityCase($contribution->severity)),
            ScaffoldKind::Step => sprintf(
                'new FlowStep(%s, %s, %s, StepPosition::%s%s)',
                $id,
                $point,
                $command,
                $contribution->position === StepPosition::BeforeSubmit ? 'BeforeSubmit' : 'AfterReceipt',
                $contribution->patches === [] ? '' : sprintf(', patches: [%s]', implode(', ', array_map(static fn (string $path): string => "'".$path."'", $contribution->patches))),
            ),
        };
    }

    private static function fill(StubContribution $contribution): GeneratedFile
    {
        $id = $contribution->id->value;
        $module = ScaffoldNames::module($contribution->id);
        $props = $contribution->point->props;
        $result = $contribution->query instanceof CommandRef ? ScaffoldNames::result($contribution->query) : null;
        $generated = $result === null ? ['Issues'] : ['Issues', $result];
        sort($generated, SORT_STRING);

        $lines = [
            sprintf('// The contribution %s: a section on %s, rendered with the point\'s props%s.', $id, $contribution->point->id->toString(), $result === null ? '' : ' and the result of its data query '.$contribution->query?->toString()),
            '',
            ...self::imports($contribution->point, ['usePanelHost'], ['SlotProps'], $generated),
            '',
            sprintf('/** What %s renders on %s. */', $id, $contribution->point->id->toString()),
            sprintf('export default function %s(input: SlotProps<%s>) {', $module, $result === null ? $props : $props.', '.$result),
            '  const panel = usePanelHost<Issues>();',
            '',
            ...($result === null ? [] : [
                "  if (input.data.status !== 'ready') {",
                sprintf("    return <p>{panel.t(`%s.${input.data.status}`)}</p>;", $id),
                '  }',
                '',
            ]),
            sprintf("  return <p>{panel.t('%s.body', { fields: Object.keys(input.props).length })}</p>;", $id),
            '}',
            '',
        ];

        return new GeneratedFile(ScaffoldNames::SOURCE.'/'.$module.'.tsx', implode("\n", $lines));
    }

    private static function fillTest(StubContribution $contribution): GeneratedFile
    {
        $id = $contribution->id->value;
        $module = ScaffoldNames::module($contribution->id);
        $result = $contribution->query instanceof CommandRef ? ScaffoldNames::result($contribution->query) : null;
        $options = [
            '    addon,',
            sprintf('    id: %s,', ScaffoldNames::quote($id)),
            sprintf('    props: %s,', self::literal($contribution->point->sample, 4, 11)),
            ...($result === null ? [] : [sprintf('    data: %s,', self::literal($contribution->resultSample, 4, 10))]),
            sprintf('    host: { namespace: %s },', ScaffoldNames::quote($contribution->id->namespace()->value)),
        ];

        $lines = [
            '// @vitest-environment jsdom',
            '',
            sprintf('// The contribution %s keeps the slot contract: it renders with the point\'s props%s, reaches', $id, $result === null ? '' : ' in each state of its data'),
            '// the panel through the host alone, and what it renders has no accessibility violation.',
            '',
            "import { expectNoA11yViolations, expectSlotContract } from '@cboxdk/cms-panel/testing';",
            "import { test } from 'vitest';",
            '',
            "import addon from './index';",
            '',
            sprintf("test('%s keeps the slot contract', async () => {", $id),
            sprintf('  const rendered = await expectSlotContract%s({', $result === null ? '' : '<typeof addon.contributions, '.$result.'>'),
            ...$options,
            '  });',
            '',
            '  await expectNoA11yViolations(rendered.container);',
            '  await rendered.unmount();',
            '});',
            '',
        ];

        if ($result !== null) {
            array_splice($lines, 8, 0, [sprintf("import type { %s } from '../generated/contributions';", $result)]);
        }

        return new GeneratedFile(ScaffoldNames::SOURCE.'/'.$module.'.test.tsx', implode("\n", $lines));
    }

    private static function check(StubContribution $contribution): GeneratedFile
    {
        $id = $contribution->id->value;
        $module = ScaffoldNames::module($contribution->id);
        $variable = ScaffoldNames::variable($contribution->id);
        $command = $contribution->command?->toString() ?? '';
        $document = $contribution->command instanceof CommandRef ? ScaffoldNames::document($contribution->command) : 'object';

        $lines = [
            sprintf('// The form check %s on the form of %s: a pure function of the document to the issues', $id, $command),
            '// it finds, synchronous and within 16 ms, each issue at the path of the value it is about with',
            "// a code in the addon's namespace.",
            '',
            "import type { FormCheck } from '@cboxdk/cms-panel/extend';",
            '',
            sprintf("import type { %s } from '../generated/contributions';", $document),
            '',
            sprintf('/** The issues %s finds in a %s document; it finds none until the addon gives it its rule. */', $id, $command),
            sprintf('export const %sCheck: FormCheck<%s> = () => [];', $variable, $document),
            '',
        ];

        return new GeneratedFile(ScaffoldNames::SOURCE.'/'.$module.'Check.ts', implode("\n", $lines));
    }

    private static function checkTest(StubContribution $contribution): GeneratedFile
    {
        $id = $contribution->id->value;
        $module = ScaffoldNames::module($contribution->id);
        $document = $contribution->command instanceof CommandRef ? ScaffoldNames::document($contribution->command) : 'object';

        $lines = [
            sprintf('// The form check %s keeps the form check contract: on a document of its command it answers', $id),
            "// within the host's budget with issues in the addon's namespace no heavier than its manifest declares,",
            '// and answers the same twice.',
            '',
            "import { expectFormCheckContract } from '@cboxdk/cms-panel/testing';",
            "import { test } from 'vitest';",
            '',
            sprintf("import type { %s } from '../generated/contributions';", $document),
            "import addon from './index';",
            '',
            sprintf('const document: %s = %s;', $document, self::literal($contribution->commandSample, 0, strlen($document) + 20)),
            '',
            sprintf("test('%s keeps the form check contract', () => {", $id),
            '  expectFormCheckContract({',
            '    addon,',
            sprintf('    id: %s,', ScaffoldNames::quote($id)),
            sprintf('    namespace: %s,', ScaffoldNames::quote($contribution->id->namespace()->value)),
            sprintf('    severity: %s,', ScaffoldNames::quote($contribution->severity->value)),
            '    documents: [document],',
            '  });',
            '});',
            '',
        ];

        return new GeneratedFile(ScaffoldNames::SOURCE.'/'.$module.'Check.test.ts', implode("\n", $lines));
    }

    private static function step(StubContribution $contribution): GeneratedFile
    {
        $id = $contribution->id->value;
        $module = ScaffoldNames::module($contribution->id);
        $command = $contribution->command?->toString() ?? '';
        $document = $contribution->command instanceof CommandRef ? ScaffoldNames::document($contribution->command) : 'object';
        $paths = $contribution->patches === [] ? 'never' : implode(' | ', array_map(ScaffoldNames::quote(...), $contribution->patches));
        $generated = ['Issues', $document];
        sort($generated, SORT_STRING);

        $lines = [
            sprintf('// The flow step %s of the form of %s, %s: a numbered step the viewer', $id, $command, str_replace('_', ' ', $contribution->position->value)),
            '// goes through, which patches only the paths its manifest declares and ends with next() or',
            '// cancel() when the viewer acts.',
            '',
            "import { usePanelHost, type StepProps } from '@cboxdk/cms-panel/extend';",
            '',
            sprintf("import type { %s } from '../generated/contributions';", implode(', ', $generated)),
            '',
            sprintf('/** The step %s runs %s of %s. */', $id, str_replace('_', ' ', $contribution->position->value), $command),
            sprintf('export default function %sStep(step: StepProps<%s, %s, Issues>) {', $module, $document, $paths),
            '  const panel = usePanelHost<Issues>();',
            '',
            '  return (',
            '    <div>',
            sprintf("      <p>{panel.t('%s.body')}</p>", $id),
            '      <button type="button" onClick={step.next}>',
            sprintf("        {panel.t('%s.next')}", $id),
            '      </button>',
            '    </div>',
            '  );',
            '}',
            '',
        ];

        return new GeneratedFile(ScaffoldNames::SOURCE.'/'.$module.'Step.tsx', implode("\n", $lines));
    }

    private static function stepTest(StubContribution $contribution): GeneratedFile
    {
        $id = $contribution->id->value;
        $module = ScaffoldNames::module($contribution->id);
        $document = $contribution->command instanceof CommandRef ? ScaffoldNames::document($contribution->command) : 'object';
        $patches = implode(', ', array_map(ScaffoldNames::quote(...), $contribution->patches));

        $lines = [
            '// @vitest-environment jsdom',
            '',
            sprintf('// The flow step %s keeps the flow step contract: it renders with the draft, patches only the', $id),
            '// paths its manifest declares, waits for the viewer before it ends the flow, and what it renders',
            '// has no accessibility violation.',
            '',
            "import { expectFlowStepContract, expectNoA11yViolations } from '@cboxdk/cms-panel/testing';",
            "import { test } from 'vitest';",
            '',
            sprintf("import type { %s } from '../generated/contributions';", $document),
            "import addon from './index';",
            '',
            sprintf('const draft: %s = %s;', $document, self::literal($contribution->commandSample, 0, strlen($document) + 17)),
            '',
            sprintf("test('%s keeps the flow step contract', async () => {", $id),
            '  const rendered = await expectFlowStepContract({',
            '    addon,',
            sprintf('    id: %s,', ScaffoldNames::quote($id)),
            '    draft,',
            sprintf('    patches: [%s],', $patches),
            '  });',
            '',
            '  await expectNoA11yViolations(rendered.container);',
            '  await rendered.unmount();',
            '});',
            '',
        ];

        return new GeneratedFile(ScaffoldNames::SOURCE.'/'.$module.'Step.test.tsx', implode("\n", $lines));
    }

    /**
     * The import lines of a module: the SDK's values and types, the point's props from the subpath
     * of its stability, and the generated types.
     *
     * @param  list<string>  $values
     * @param  list<string>  $types
     * @param  list<string>  $generated
     * @return list<string>
     */
    private static function imports(StubPoint $point, array $values, array $types, array $generated): array
    {
        $extend = [...$values, ...array_map(static fn (string $type): string => 'type '.$type, $types)];

        if ($point->stable) {
            $extend[] = 'type '.$point->props;
        }

        $lines = [sprintf("import { %s } from '%s';", implode(', ', $extend), ContributionsModule::EXTEND)];

        if (! $point->stable) {
            $lines[] = sprintf("import type { %s } from '%s';", $point->props, ContributionsModule::EXPERIMENTAL);
        }

        $lines[] = '';
        $lines[] = sprintf("import type { %s } from '../generated/contributions';", implode(', ', $generated));

        return $lines;
    }

    /**
     * A sample as a TypeScript literal, printed from the column, or an empty object when the
     * schema gave none.
     */
    private static function literal(?Literal $literal, int $indent, int $column): string
    {
        return $literal instanceof Literal ? LiteralPrinter::print($literal, $column, $indent, 1) : '{}';
    }

    private static function severityCase(Severity $severity): string
    {
        return ucfirst($severity->value);
    }
}
