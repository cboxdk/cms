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
