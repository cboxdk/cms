// The runtime registration check (section 3.1 of the panel extension architecture): the host takes
// the digest of the ids an addon registered as the server takes it of the ids cms:build compiled,
// and accepts only a definePanelAddon() registration of exactly those, made with the panel API's
// major version and no newer minor.

import { definePanelAddon, PANEL_API_VERSION } from '@cboxdk/cms-panel/extend';
import { createHash } from 'node:crypto';
import { describe, expect, test } from 'vitest';

import { checkRegistration, registrationDigest, registrationOf } from '../../src/host/registration';
import { serverDigest } from './harness';

const CARD = () => Promise.resolve({ default: () => null });

function entry(ids: readonly string[]) {
  return { addon: 'alpha', any_command: false, issues: [], registration: serverDigest(ids) };
}

describe('the registration digest', () => {
  test('is the SHA-256 of the sorted ids joined by line feeds, in lowercase hexadecimal, as the server takes it', async () => {
    expect(await registrationDigest(['alpha.b', 'alpha.a'])).toBe(
      createHash('sha256').update('alpha.a\nalpha.b').digest('hex'),
    );
    expect(await registrationDigest([])).toBe(createHash('sha256').update('').digest('hex'));
  });
});

describe('the registration check', () => {
  test('accepts a registration of exactly the compiled ids', async () => {
    const checked = await checkRegistration(entry(['alpha.card', 'alpha.check']), {
      default: definePanelAddon({ 'alpha.card': CARD, 'alpha.check': () => [] }),
    });

    expect(checked.status).toBe('registered');
  });

  test('refuses a missing id, an extra id, a module without a registration and another version of the panel API', async () => {
    const compiled = entry(['alpha.card', 'alpha.check']);
    const outcomes = await Promise.all([
      checkRegistration(compiled, { default: definePanelAddon({ 'alpha.card': CARD }) }),
      checkRegistration(compiled, {
        default: definePanelAddon({
          'alpha.card': CARD,
          'alpha.check': () => [],
          'alpha.extra': CARD,
        }),
      }),
      checkRegistration(compiled, { default: { 'alpha.card': CARD, 'alpha.check': CARD } }),
      checkRegistration(compiled, {
        default: {
          contributions: { 'alpha.card': CARD, 'alpha.check': CARD },
          ids: ['alpha.card', 'alpha.check'],
          sdk: { major: PANEL_API_VERSION.major + 1, minor: 0 },
        },
      }),
      checkRegistration(compiled, {
        default: {
          contributions: { 'alpha.card': CARD, 'alpha.check': CARD },
          ids: ['alpha.card', 'alpha.check'],
          sdk: { major: PANEL_API_VERSION.major, minor: PANEL_API_VERSION.minor + 1 },
        },
      }),
      checkRegistration(compiled, {
        default: {
          contributions: { 'alpha.card': CARD, 'alpha.check': 'not a function' },
          ids: ['alpha.card', 'alpha.check'],
          sdk: PANEL_API_VERSION,
        },
      }),
    ]);

    expect(outcomes.map((outcome) => outcome.status)).toEqual([
      'mismatch',
      'mismatch',
      'mismatch',
      'mismatch',
      'mismatch',
      'mismatch',
    ]);
  });

  test('reads the registration a module exports as its default', () => {
    expect(registrationOf({ default: definePanelAddon({ 'alpha.card': CARD }) })?.ids).toEqual([
      'alpha.card',
    ]);
    expect(registrationOf(null)).toBeUndefined();
  });
});
