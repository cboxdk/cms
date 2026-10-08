// @vitest-environment jsdom

// The addon testkit, @cboxdk/cms-panel/testing: renderPoint() and the renderers per kind render a
// contribution of a registration the way the host does, with frozen props, its data and a fake
// host; the conformance helpers pass a contribution that keeps its kind's contract and name what
// one that breaks it did; checkParity() holds a mirrored check to its hook's verdicts;
// expectRegistration() holds a registration to the manifest's ids; and expectNoA11yViolations()
// runs axe on what was rendered.

import {
  definePanelAddon,
  usePanelHost,
  type Decorator,
  type FormCheck,
  type Observer,
  type ProviderProps,
  type SlotProps,
  type StepProps,
  type ToolbarItem,
} from '@cboxdk/cms-panel/extend';
import {
  A11yViolations,
  checkParity,
  ContributionContractBroken,
  createFakeHost,
  expectDecoratorKeepsDefault,
  expectFlowStepContract,
  expectFormCheckContract,
  expectNoA11yViolations,
  expectObserverContract,
  expectPageContract,
  expectProviderContract,
  expectRegistration,
  expectReplacementContract,
  expectSlotContract,
  ParityBroken,
  RegistrationMismatch,
  renderDecorator,
  renderPoint,
  renderReplacement,
  renderSlot,
  renderStep,
} from '@cboxdk/cms-panel/testing';
import { act } from 'react';
import { describe, expect, test } from 'vitest';

interface Note {
  readonly note: string;
}

interface Draft {
  readonly fields: { readonly title: string; readonly ext?: { readonly acme?: object } };
}

function Card({ props, data }: SlotProps<Note, number>) {
  const panel = usePanelHost();

  return (
    <p lang={panel.locale} data-status={data.status}>
      {panel.t('acme.card', { note: props.note })}
    </p>
  );
}

function Plain({ props }: SlotProps<Note>) {
  return <p>{props.note}</p>;
}

function Replace({ note }: Note) {
  return <p>{note}</p>;
}

function Page() {
  const panel = usePanelHost();

  return <h1>{panel.t('acme.page.title')}</h1>;
}

function Broken(): never {
  throw new Error('The card broke.');
}

const badge: ToolbarItem<Note> = (props) => ({
  kind: 'badge',
  label: 'acme.badge',
  parameters: { note: props.note },
});

const decorate: Decorator<Note, 'disabled_reason' | 'tone_towards_danger'> = (props) => ({
  before: <em>{props.note}</em>,
  badge: { tone: 'warning', label: 'acme.flag' },
  tighten: { disabled_reason: 'acme.frozen', tone_towards_danger: 'danger' },
});

const overreaching: Decorator<Note, 'disabled_reason'> = () => ({
  tighten: { disabled_reason: 'acme.frozen', description: 'acme.more' } as never,
});

function Step({ draft, patch, next }: StepProps<Draft, 'fields.ext.acme.reason'>) {
  return (
    <button
      type="button"
      onClick={() => {
        patch('fields.ext.acme.reason', 'late');
        next();
      }}
    >
      {draft.fields.title}
    </button>
  );
}

function Eager({ next }: StepProps<Draft>) {
  next();

  return null;
}

const titleCheck: FormCheck<Draft> = (document) =>
  document.fields.title === ''
    ? [
        {
          path: 'fields.title',
          code: 'acme.title_missing',
          severity: 'error',
          message: 'acme.title',
        },
      ]
    : [];

const slowCheck: FormCheck<Draft> = () => {
  const started = performance.now();

  while (performance.now() - started < 20) {
    // Burns the budget.
  }

  return [];
};

const observe: Observer<Note> = () => undefined;

function Wrapper({ children }: ProviderProps<Note>) {
  return <section>{children}</section>;
}

const addon = definePanelAddon({
  'acme.card': () => Promise.resolve({ default: Card }),
  'acme.plain': () => Promise.resolve({ default: Plain }),
  'acme.replace': () => Promise.resolve({ default: Replace }),
  'acme.broken': () => Promise.resolve({ default: Broken }),
  'acme.badge': () => Promise.resolve({ default: badge }),
  'acme.column': () =>
    Promise.resolve({ default: { header: 'acme.header', width: 'narrow', cell: Plain } }),
  'acme.tab': () => Promise.resolve({ default: { label: 'acme.tab', component: Plain } }),
  'acme.page': () => Promise.resolve({ default: Page }),
  'acme.decorate': decorate,
  'acme.overreach': overreaching,
  'acme.step': () => Promise.resolve({ default: Step }),
  'acme.eager': () => Promise.resolve({ default: Eager }),
  'acme.title': titleCheck,
  'acme.slow': slowCheck,
  'acme.observe': observe,
  'acme.wrap': () => Promise.resolve({ default: Wrapper }),
  'acme.empty': () => Promise.resolve({ default: () => null }),
});

describe('renderPoint()', () => {
  test('renders a slot contribution with the point s props frozen, its data and a fake host', async () => {
    const result = await renderSlot<typeof addon.contributions, number>({
      addon,
      id: 'acme.card',
      props: { note: 'Weekly' },
      data: { status: 'ready', value: 3 },
      host: { namespace: 'acme', locale: 'da', texts: { 'acme.card': 'Note {note}' } },
    });

    expect(result.container.innerHTML).toBe('<p lang="da" data-status="ready">Note Weekly</p>');
    expect(Object.isFrozen(result.host.record)).toBe(false);
    await result.unmount();
    expect(document.body.contains(result.container)).toBe(false);
  });

  test('hands a function among the props over by reference and freezes the rest, as the host does for a field input s onChange', async () => {
    const seen: {
      props?: {
        readonly value: { readonly text: string };
        readonly onChange: (value: string) => void;
      };
    } = {};
    const changes: string[] = [];
    const onChange = (value: string): void => {
      changes.push(value);
    };
    const capture = definePanelAddon({
      'acme.input': () =>
        Promise.resolve({
          default: (props: {
            readonly value: { readonly text: string };
            readonly onChange: (value: string) => void;
          }) => {
            seen.props = props;

            return null;
          },
        }),
    });

    const result = await renderReplacement({
      addon: capture,
      id: 'acme.input',
      props: { value: { text: 'Weekly' }, onChange },
    });

    expect(seen.props?.onChange).toBe(onChange);
    expect(Object.isFrozen(seen.props)).toBe(true);
    expect(Object.isFrozen(seen.props?.value)).toBe(true);
    seen.props?.onChange('Monthly');
    expect(changes).toEqual(['Monthly']);
    await result.unmount();
  });

  test('renders what the host makes of a toolbar item, a column and a tab', async () => {
    const toolbar = await renderSlot({
      addon,
      id: 'acme.badge',
      region: 'toolbar',
      props: { note: 'Weekly' },
    });
    const column = await renderSlot({
      addon,
      id: 'acme.column',
      region: 'columns',
      props: { note: 'Row' },
    });
    const tab = await renderSlot({ addon, id: 'acme.tab', region: 'tabs', props: { note: 'Tab' } });

    expect(toolbar.item).toEqual({
      kind: 'badge',
      label: 'acme.badge',
      parameters: { note: 'Weekly' },
    });
    expect(column.header).toBe('acme.header');
    expect(column.container.innerHTML).toBe('<p>Row</p>');
    expect(tab.label).toBe('acme.tab');
    expect(tab.container.innerHTML).toBe('<p>Tab</p>');
  });

  test('names a contribution that throws while it renders, and one the registration lacks', async () => {
    await expect(renderPoint({ kind: 'slot', addon, id: 'acme.broken' })).rejects.toThrow(
      'The contribution acme.broken breaks its contract: it threw while rendering: Error: The card broke.',
    );
    await expect(renderPoint({ kind: 'slot', addon, id: 'acme.missing' as never })).rejects.toThrow(
      ContributionContractBroken,
    );
  });

  test('composes a decorator onto a default that renders once, and keeps undeclared tightenings apart', async () => {
    const result = await renderDecorator({
      addon,
      id: 'acme.decorate',
      props: { note: 'Weekly' },
      tightens: ['disabled_reason', 'tone_towards_danger'],
      host: { namespace: 'acme', texts: { 'acme.frozen': 'Frozen', 'acme.flag': 'Flagged' } },
      defaultContent: <button type="button" data-default="save" />,
    });

    expect(result.container.innerHTML).toBe(
      '<em>Weekly</em>Flagged<button type="button" data-default="save"></button>',
    );
    expect(result.tightened).toEqual({
      disabled: true,
      disabledReasons: ['Frozen'],
      descriptions: [],
      tone: 'danger',
    });
    expect(result.badges).toEqual([{ tone: 'warning', label: 'acme.flag' }]);

    const overreach = await renderDecorator({
      addon,
      id: 'acme.overreach',
      tightens: ['disabled_reason'],
    });

    expect(overreach.refusedTightenings).toEqual(['description']);
    expect(overreach.tightened.disabled).toBe(true);
  });

  test('gives a flow step the controls the host gives it, and records what it did with them', async () => {
    const result = await renderStep({
      addon,
      id: 'acme.step',
      draft: { fields: { title: 'Hello' } },
      patches: ['fields.ext.acme.reason'],
    });

    expect(result.container.textContent).toBe('Hello');

    await act(async () => {
      result.container.querySelector('button')?.click();
      await Promise.resolve();
    });

    expect(result.step.patches).toEqual([{ path: 'fields.ext.acme.reason', value: 'late' }]);
    expect(result.step.draft).toEqual({
      fields: { title: 'Hello', ext: { acme: { reason: 'late' } } },
    });
    expect(result.step.next).toBe(1);
    expect(result.step.cancelled).toBeNull();

    const refused = await renderStep({ addon, id: 'acme.step', draft: { fields: { title: 'x' } } });

    await act(async () => {
      refused.container.querySelector('button')?.click();
      await Promise.resolve();
    });

    expect(refused.step.refusedPatches).toEqual(['fields.ext.acme.reason']);
    expect(refused.step.patches).toEqual([]);
  });
});

describe('the conformance helpers', () => {
  test('pass a contribution of each kind that keeps its contract', async () => {
    const slot = await expectSlotContract<typeof addon.contributions, number>({
      addon,
      id: 'acme.card',
      props: { note: 'n' },
      data: 2,
    });
    expect(slot.container.querySelector('[data-status="ready"]')).not.toBeNull();

    await expectPageContract({ addon, id: 'acme.page' });
    await expectReplacementContract({ addon, id: 'acme.replace', props: { note: 'n' } });
    await expectProviderContract({ addon, id: 'acme.wrap', props: { note: 'n' } });
    await expectDecoratorKeepsDefault({
      addon,
      id: 'acme.decorate',
      props: { note: 'n' },
      tightens: ['disabled_reason', 'tone_towards_danger'],
    });
    await expectFlowStepContract({
      addon,
      id: 'acme.step',
      draft: { fields: { title: 't' } },
      patches: ['fields.ext.acme.reason'],
    });
    expect(
      expectFormCheckContract({
        addon,
        id: 'acme.title',
        namespace: 'acme',
        severity: 'error',
        documents: [{ fields: { title: '' } }, { fields: { title: 'Set' } }],
      }).map((issues) => issues.length),
    ).toEqual([1, 0]);
    expectObserverContract({ addon, id: 'acme.observe', events: [{ note: 'n' }] });
  });

  test('name what a contribution that breaks its contract did', async () => {
    await expect(expectReplacementContract({ addon, id: 'acme.empty' })).rejects.toThrow(
      'a replacement renders something in place of the default; this one rendered nothing.',
    );
    await expect(
      expectDecoratorKeepsDefault({ addon, id: 'acme.overreach', tightens: ['disabled_reason'] }),
    ).rejects.toThrow('it tightens description, which its manifest does not declare');
    await expect(
      expectFlowStepContract({ addon, id: 'acme.eager', draft: { fields: { title: 't' } } }),
    ).rejects.toThrow('it ended the flow while rendering');
    expect(() =>
      expectFormCheckContract({
        addon,
        id: 'acme.title',
        namespace: 'other',
        severity: 'error',
        documents: [{ fields: { title: '' } }],
      }),
    ).toThrow("the issue code acme.title_missing is outside the addon's namespace other.");
    expect(() =>
      expectFormCheckContract({
        addon,
        id: 'acme.title',
        namespace: 'acme',
        severity: 'warning',
        documents: [{ fields: { title: '' } }],
      }),
    ).toThrow('heavier than the warning its manifest declares');
    expect(() =>
      expectFormCheckContract({
        addon,
        id: 'acme.slow',
        namespace: 'acme',
        severity: 'info',
        documents: [{ fields: { title: '' } }],
      }),
    ).toThrow('over the budget of 16 ms');
  });
});

describe('checkParity()', () => {
  test('passes when the check blocks exactly what the hook refuses, and names each disagreement', async () => {
    const documents: Draft[] = [{ fields: { title: '' } }, { fields: { title: 'Set' } }];

    await expect(
      checkParity(
        titleCheck,
        (document) => (document.fields.title === '' ? ['fields.title'] : []),
        documents,
      ),
    ).resolves.toBe(2);

    await expect(checkParity(titleCheck, () => Promise.resolve([]), documents)).rejects.toThrow(
      new ParityBroken([{ index: 0, check: ['fields.title'], hook: [] }]).message,
    );
  });
});

describe('expectRegistration()', () => {
  test('holds the registration to the manifest s ids', () => {
    expectRegistration(definePanelAddon({ 'acme.a': observe, 'acme.b': observe }), [
      'acme.b',
      'acme.a',
    ]);

    expect(() => {
      expectRegistration(definePanelAddon({ 'acme.a': observe, 'acme.c': observe }), [
        'acme.a',
        'acme.b',
      ]);
    }).toThrow(new RegistrationMismatch(['acme.b'], ['acme.c']).message);
  });
});

describe('expectNoA11yViolations()', () => {
  test('passes accessible markup and lists the violations of markup that is not', async () => {
    const good = await renderSlot({ addon, id: 'acme.plain', props: { note: 'Fine' } });

    await expect(expectNoA11yViolations(good.container)).resolves.toBeUndefined();

    const bad = document.createElement('div');
    bad.innerHTML = '<button type="button"></button>';
    document.body.append(bad);

    await expect(expectNoA11yViolations(bad)).rejects.toThrow(A11yViolations);
    await expect(expectNoA11yViolations(bad)).rejects.toThrow('button-name');
    bad.remove();
  });
});

describe('the fake host inside a rendered contribution', () => {
  test('is the one the test built', async () => {
    const host = createFakeHost({ namespace: 'acme', texts: { 'acme.card': 'Card {note}' } });
    const result = await renderSlot<typeof addon.contributions, number>({
      addon,
      id: 'acme.card',
      props: { note: 'x' },
      data: { status: 'loading' },
      host,
    });

    expect(result.host).toBe(host);
    expect(result.container.textContent).toBe('Card x');
  });
});
