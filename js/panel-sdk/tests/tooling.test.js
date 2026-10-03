// The configurations an addon's repository takes from the SDK: the ESLint configuration refuses
// the imports the panel keeps to itself and holds the panel's own rules, the stylelint
// configuration keeps an addon's styles on the design tokens, and the Storybook preset adds the
// SDK's preview, which renders a story in the kit's locale and theme.

import assert from 'node:assert/strict';
import { existsSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import process from 'node:process';
import { ESLint } from 'eslint';
import { describe, test } from 'vitest';

import cmsPanelAddonEslint, { REFUSED_IMPORTS } from '../eslint.js';
import { addons, PREVIEW, previewAnnotations } from '../storybook/preset.js';
import stylelintConfig from '../stylelint.js';
import cmsPanelAddon from '../vite.js';
import { ROOT } from './sdk.js';

/**
 * Lints a probe of an addon's code, written into the SDK's tests so the repository's tsconfig.json
 * holds it, with the SDK's configuration alone.
 *
 * @param {string} code
 * @returns {Promise<string[]>} the rules that failed
 */
async function lintAddon(code) {
  const path = join(
    ROOT,
    'js/panel-sdk/tests',
    `cms-probe-${String(process.pid)}-${String(Date.now())}.tsx`,
  );
  writeFileSync(path, code);

  try {
    const eslint = new ESLint({
      cwd: ROOT,
      overrideConfigFile: true,
      overrideConfig: cmsPanelAddonEslint({ tsconfigRootDir: ROOT }),
    });
    const [result] = await eslint.lintFiles([path]);

    return (result?.messages ?? []).map((message) => message.ruleId ?? message.message);
  } finally {
    rmSync(path, { force: true });
  }
}

describe('the ESLint configuration of an addon', () => {
  test(
    'refuses Inertia, React Aria and the kit, and literal UI text',
    { timeout: 120_000 },
    async () => {
      const rules = await lintAddon(
        [
          "import { router } from '@inertiajs/react';",
          "import { Button } from 'react-aria-components';",
          "import { Card } from '@cboxdk/cms-ui-kit';",
          '',
          'export function Probe() {',
          '  return <p>{String([router, Button, Card].length)} Approvals</p>;',
          '}',
          '',
        ].join('\n'),
      );

      assert.deepEqual(
        rules.filter((rule) => rule === 'no-restricted-imports').length,
        3,
        rules.join(', '),
      );
      assert.ok(rules.includes('cms/no-literal-ui-text'), rules.join(', '));
    },
  );

  test('passes an addon that uses the SDK and its catalogue', { timeout: 120_000 }, async () => {
    const rules = await lintAddon(
      [
        "import { usePanelHost } from '@cboxdk/cms-panel/extend';",
        '',
        'export function Probe() {',
        '  const { t } = usePanelHost();',
        "  return <p>{t('reviews.heading')}</p>;",
        '}',
        '',
      ].join('\n'),
    );

    assert.deepEqual(rules, []);
  });

  test('refuses what the build plugin refuses', () => {
    const groups = REFUSED_IMPORTS.flatMap((refused) => refused.group);

    assert.ok(
      groups.includes('@inertiajs/*') &&
        groups.includes('react-aria-components') &&
        groups.includes('@cboxdk/cms-ui-kit'),
    );
    assert.equal(cmsPanelAddon().name, 'cboxdk-cms-panel-addon');
  });
});

describe('the stylelint configuration of an addon', () => {
  test('keeps styles on the tokens and in the cascade layers', () => {
    assert.equal(stylelintConfig.rules['declaration-no-important'], true);
    assert.equal(stylelintConfig.rules['color-no-hex'], true);
    assert.equal(stylelintConfig.rules['selector-max-id'], 0);
    assert.deepEqual(stylelintConfig.rules['at-rule-disallowed-list'], ['import', 'font-face']);
    assert.ok(Object.isFrozen(stylelintConfig) && Object.isFrozen(stylelintConfig.rules));
  });
});

describe('the Storybook preset of an addon', () => {
  test('adds the SDK preview after the others, with the accessibility addon', () => {
    assert.deepEqual(previewAnnotations(['/a/preview.js']), ['/a/preview.js', PREVIEW]);
    assert.deepEqual(previewAnnotations(), [PREVIEW]);
    assert.ok(existsSync(PREVIEW));
    assert.deepEqual(addons, ['@storybook/addon-a11y']);
  });
});
