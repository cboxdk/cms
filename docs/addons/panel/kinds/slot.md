---
title: Slot
weight: 21
description: "A slot: components or descriptors an addon adds to a region of a page, with the point's props and the result of a data query, and the rules the host renders them by."
---

# Slot

A slot is a place on a page where contributions add their own UI, such as a section of a page or the aside of a form. A contribution is a `SlotFill` in the manifest and a component in the addon's bundle.

| | |
|---|---|
| Manifest | `new SlotFill($id, '<point>@<version>', data: <query class>, priority: 1000, scope: new Scope(...))` |
| Bundle | a `SlotComponent<P, D>` of `@cboxdk/cms-panel/extend`, registered as `() => import('./Module')` |
| Receives | `props`, the point's props, frozen, and with a data query `data`, a `DataState<D>`: `loading`, `ready` with the value, or `failed` with a code |
| Scaffold | `cms:make:panel fill <namespace> <id>` |
| Test | `expectSlotContract()` and `expectNoA11yViolations()` |
| Points | [`account.me.sections@1`](../points/account-me-sections.md), [`access.roles.sections@1`](../points/access-roles-sections.md), [`access.grants.sections@1`](../points/access-grants-sections.md), [`command.form.aside@1`](../points/command-form-aside.md), [`command.form.dryrun@1`](../points/command-form-dryrun.md) |

## Regions

A slot sits in a `Region`. In `Sections` and `Aside` a component renders its own markup with the kit's components. In `Toolbar`, `Columns` and `Tabs` a contribution gives descriptors the host renders with the kit, `ToolbarItem`, `ColumnDescriptor` and `TabDescriptor`, so tables, tabs and toolbars stay accessible. Every slot of block B1 is in `Sections` or `Aside`.

## Data

A `SlotFill` may name a `#[Query]` of its own addon as `data`. The server runs it through the query pipeline as the viewer, with its input taken from the point's props by name, at the lower of the viewer's classification access and the addon's `reads`, and sends the result as a deferred prop after the page has rendered. A query the pipeline refuses, or one that throws, leaves the data `failed`, and the page still renders: a section says what it waits for while it loads and what failed when it did not come. [What a page sends a viewer](../contributions.md#what-a-page-sends-a-viewer) has the details.

## Rules

- The component reaches the panel only through `usePanelHost()`: texts of its own catalogue, formatting, notices, navigation and the commands its manifest's `issues` lists.
- Its stylesheet stays in its own part of the page: the SDK's Vite plugin puts every rule in the layer `cms.addon.<namespace>` and scopes its selectors to `[data-cms-addon="<namespace>"]`, the element the host renders each contribution inside.
- A slot renders at most its point's maximum of contributions.

## Example

The addon `acme/cms-notes` adds a section with the result of its data query:

<!-- example: examples/Vitest/Panel/Kinds/slot.test.tsx -->
```tsx
// @vitest-environment jsdom

// A slot contribution, as cms:make:panel fill scaffolds it: the addon acme/cms-notes adds a section
// to a slot in a sections region, with the point's props and the result of its data query. The
// component renders its own markup in each state of its data, says what it waits for while the
// data loads and what failed when it did not come, and reaches the panel through the host alone.
// expectSlotContract() renders it loading, failed and ready, as the host may.

import { definePanelAddon, usePanelHost, type SlotProps } from '@cboxdk/cms-panel/extend';
import { expectNoA11yViolations, expectSlotContract } from '@cboxdk/cms-panel/testing';
import { expect, test } from 'vitest';

interface ActorProps {
  readonly actor: string;
}

interface NotesResult {
  readonly count: number;
}

/** The notes the viewer wrote, read with the addon's query notes.mine. */
function MyNotes({ data }: SlotProps<ActorProps, NotesResult>) {
  const panel = usePanelHost();

  if (data.status === 'loading') {
    return <p>{panel.t('notes.my_notes.loading')}</p>;
  }

  if (data.status === 'failed') {
    return <p>{panel.t('notes.my_notes.failed', { code: data.code })}</p>;
  }

  return (
    <section aria-label={panel.t('notes.my_notes.title')}>
      <p>{panel.t('notes.my_notes.count', { count: panel.formatNumber(data.value.count) })}</p>
    </section>
  );
}

const addon = definePanelAddon({
  'notes.my-notes': () => Promise.resolve({ default: MyNotes }),
});

test('notes.my-notes keeps the slot contract in every state of its data', async () => {
  const rendered = await expectSlotContract<typeof addon.contributions, NotesResult>({
    addon,
    id: 'notes.my-notes',
    props: { actor: '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01' },
    region: 'sections',
    data: { count: 1200 },
    host: {
      namespace: 'notes',
      texts: {
        'notes.my_notes.title': 'My notes',
        'notes.my_notes.count': 'You wrote {count} notes.',
        'notes.my_notes.loading': 'Loading your notes.',
        'notes.my_notes.failed': 'Your notes could not be read ({code}).',
      },
    },
  });

  expect(rendered.container.textContent).toBe('You wrote 1,200 notes.');
  await expectNoA11yViolations(rendered.container);
  await rendered.unmount();
});
```
