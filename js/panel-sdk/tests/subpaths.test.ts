// The stability of the SDK's subpaths (sections 2.1 and 6 of the panel extension architecture):
// the experimental API is exported only from @cboxdk/cms-panel/experimental, so importing it from
// /extend fails tsc, and /ui and /experimental hold the kit's exports by their stability tags.

import assert from 'node:assert/strict';
import { describe, test } from 'vitest';

import { compileProbe } from './sdk.js';

describe('an experimental type imported from /extend', () => {
  test('fails tsc, and the same import from /experimental compiles', { timeout: 60_000 }, () => {
    const errors = compileProbe({
      'probe.ts': [
        "import type { ActionHandler } from '@cboxdk/cms-panel/extend';",
        "import type { ButtonProps } from '@cboxdk/cms-panel/extend';",
        'export type Probe = readonly [ActionHandler<object>, ButtonProps];',
        '',
      ].join('\n'),
    });

    assert.deepEqual(
      errors.map((error) => [error.line, error.code]),
      [
        [1, 2305],
        [2, 2305],
      ],
    );
    assert.match(errors[0]?.message ?? '', /has no exported member 'ActionHandler'/);

    assert.deepEqual(
      compileProbe({
        'probe.ts': [
          "import type { ActionHandler, ButtonProps } from '@cboxdk/cms-panel/experimental';",
          'export type Probe = readonly [ActionHandler<object>, ButtonProps];',
          '',
        ].join('\n'),
      }),
      [],
    );
  });

  test('of the kit: /ui exports only its stable API', { timeout: 60_000 }, () => {
    const errors = compileProbe({
      'probe.ts': [
        "import { KitI18nProvider } from '@cboxdk/cms-panel/ui';",
        "import { Button } from '@cboxdk/cms-panel/ui';",
        'export const probe = [KitI18nProvider, Button];',
        '',
      ].join('\n'),
    });

    assert.deepEqual(
      errors.map((error) => [error.line, error.code]),
      [[2, 2305]],
    );
  });
});
