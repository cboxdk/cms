---
title: Page
weight: 24
description: "A page: a page of the addon below /x/<namespace>/ in the panel's shell, whose only props are the result of its data query, the same data REST answers."
---

# Page

A page is a whole page of the addon in the panel's shell, at `<prefix>/x/<namespace>/<path>`. Its only props are the result of its data query, so the same data can be read over REST with the same credential.

| | |
|---|---|
| Manifest | `new PageContribution($id, 'shell.page@1', '<path>', <query class>, scope: new Scope(requires: ...))` |
| Bundle | a `PageComponent<D>` of `@cboxdk/cms-panel/extend`, registered as `() => import('./Module')` |
| Receives | `data`, a `DataState<D>` of its query's result |
| Scaffold | none; register the module by hand |
| Test | `expectPageContract()` and `expectNoA11yViolations()` |
| Points | [`shell.page@1`](../points/shell-page.md) |

## Rules

- The query is a `#[Query]` of the addon without required input, run on the page alone, as the viewer, at the lower of the viewer's access and the addon's `reads`.
- A path no addon has, and the page of a viewer who may not open it, answer the panel's page for an address it does not have, with 404.
- The page renders the kit's `Page` and `PageHeader`, which give it its heading, and renders in each state of its data.
- Two pages of one addon cannot share a path, and pages of two addons cannot collide, because each is below its own namespace.

## Example

The addon `acme/cms-notes` has a page of every note the viewer may read:

<!-- example: examples/Vitest/Panel/Kinds/page.test.tsx -->
```tsx
// @vitest-environment jsdom

// A page contribution: the addon acme/cms-notes has a page of its own below /x/notes/, whose only
// props are the result of its data query, the same data REST answers the viewer with. The page
// renders the kit's Page and PageHeader, which give it its heading, says what it waits for and
// what failed, and lists the notes when the data is ready. expectPageContract() renders it in each
// state of its data.

import { definePanelAddon, usePanelHost, type PageProps } from '@cboxdk/cms-panel/extend';
import { Page, PageHeader } from '@cboxdk/cms-panel/experimental';
import { expectNoA11yViolations, expectPageContract } from '@cboxdk/cms-panel/testing';
import { expect, test } from 'vitest';

interface NotesResult {
  readonly notes: readonly { readonly id: string; readonly title: string }[];
}

/** The page notes.all: every note the viewer may read. */
function AllNotes({ data }: PageProps<NotesResult>) {
  const panel = usePanelHost();

  return (
    <Page header={<PageHeader title={panel.t('notes.all.title')} />}>
      {data.status === 'loading' && <p>{panel.t('notes.all.loading')}</p>}
      {data.status === 'failed' && <p>{panel.t('notes.all.failed', { code: data.code })}</p>}
      {data.status === 'ready' && (
        <ul>
          {data.value.notes.map((note) => (
            <li key={note.id}>{note.title}</li>
          ))}
        </ul>
      )}
    </Page>
  );
}

const addon = definePanelAddon({
  'notes.all': () => Promise.resolve({ default: AllNotes }),
});

test('notes.all keeps the page contract in every state of its data', async () => {
  const rendered = await expectPageContract<typeof addon.contributions, NotesResult>({
    addon,
    id: 'notes.all',
    data: { notes: [{ id: '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a31', title: 'Field notes' }] },
    host: {
      namespace: 'notes',
      texts: {
        'notes.all.title': 'Notes',
        'notes.all.loading': 'Loading the notes.',
        'notes.all.failed': 'The notes could not be read ({code}).',
      },
    },
  });

  expect(rendered.container.querySelector('h1')?.textContent).toBe('Notes');
  expect(rendered.container.querySelector('li')?.textContent).toBe('Field notes');
  await expectNoA11yViolations(rendered.container);
  await rendered.unmount();
});
```
