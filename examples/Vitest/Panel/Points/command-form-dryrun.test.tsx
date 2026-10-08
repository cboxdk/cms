// @vitest-environment jsdom

// A slot contribution to command.form.dryrun@1, the sections below what a dry run of the generic
// command form would change: the fixture addon's fixtureaddon.dry-run-note renders with the point's
// props, the command, its version, the dry run's summary and its receipt, reaches the panel
// through the host alone and has no accessibility violation. The props exist only in the browser,
// once the dry run answered, so the page builds them; here they are the sample props of the
// point's JSON Schema.

import type { DryRunViewV1 } from '@cboxdk/cms-panel/experimental';
import { expectNoA11yViolations, expectSlotContract } from '@cboxdk/cms-panel/testing';
import { expect, test } from 'vitest';

import addon from '../../../../workbench/addons/fixtureaddon/resources/panel/src/panel';

const props: DryRunViewV1 = {
  command: 'entry.create',
  version: 1,
  receipt: { outcome: 'dry_run' },
  summary: { blast_radius: { aggregates: [], mutations: 0 } },
};

test('fixtureaddon.dry-run-note keeps the slot contract below the dry run', async () => {
  const rendered = await expectSlotContract({
    addon,
    id: 'fixtureaddon.dry-run-note',
    props,
    region: 'sections',
    host: {
      namespace: 'fixtureaddon',
      texts: {
        'fixtureaddon.dry_run_note.title': 'About the slug',
        'fixtureaddon.dry_run_note.body':
          'A dry run of {command} shows no slug; it is derived on the run.',
      },
    },
  });

  expect(rendered.container.textContent).toContain('A dry run of entry.create shows no slug');
  await expectNoA11yViolations(rendered.container);
  await rendered.unmount();
});
