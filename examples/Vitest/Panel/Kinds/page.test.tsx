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
