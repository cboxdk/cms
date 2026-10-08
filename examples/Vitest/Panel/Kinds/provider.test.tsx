// @vitest-environment jsdom

// A provider contribution wraps a point's subtree: the component of the addon acme/cms-notes
// receives the point's props and the children the host renders inside it, renders them exactly
// once and reaches the panel through the host alone, as expectProviderContract() of the SDK's
// testing subpath holds it. No point of block B1 is a provider point, so the props here are the
// addon's own.

import { definePanelAddon, usePanelHost, type ProviderProps } from '@cboxdk/cms-panel/extend';
import { expectNoA11yViolations, expectProviderContract } from '@cboxdk/cms-panel/testing';
import { expect, test } from 'vitest';

interface NotebookProps {
  readonly notebook: string;
}

/** Wraps the point's subtree in a region named after the notebook the page shows. */
function NotebookRegion({ props, children }: ProviderProps<NotebookProps>) {
  const panel = usePanelHost();

  return (
    <section aria-label={panel.t('notes.notebook.region', { notebook: props.notebook })}>
      {children}
    </section>
  );
}

const addon = definePanelAddon({
  'notes.notebook-region': () => Promise.resolve({ default: NotebookRegion }),
});

test('notes.notebook-region keeps the provider contract', async () => {
  const rendered = await expectProviderContract({
    addon,
    id: 'notes.notebook-region',
    props: { notebook: 'Field notes' },
    host: { namespace: 'notes', texts: { 'notes.notebook.region': 'Notebook {notebook}' } },
  });

  expect(rendered.container.querySelector('section')?.getAttribute('aria-label')).toBe(
    'Notebook Field notes',
  );
  await expectNoA11yViolations(rendered.container);
  await rendered.unmount();
});
