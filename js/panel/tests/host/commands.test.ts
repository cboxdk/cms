// The transport of the commands a contribution issues: a POST to the panel's Inertia profile at
// `<commands>/<name>/v<version>` with an envelope of its own, answered with the flashed receipt and,
// for a rejection, the problem the page shares, each read by its generated validator.

import { describe, expect, test } from 'vitest';

import {
  commandUrl,
  CommandUnanswered,
  inertiaCommands,
  type CommandRouter,
} from '../../src/host/commands';

const RECEIPT = {
  changeset_id: null,
  consistency_token: null,
  outcome: 'rejected',
  position: null,
  projections: [],
  retention_class: 'standard',
  wait_level: 'commit',
};

const PROBLEM = {
  code: 'unauthorized',
  detail: 'The actor may not run the command.',
  errors: [],
  instance: null,
  retryable: false,
  status: 403,
  title: 'Unauthorized',
  type: 'https://cbox.dk/cms/errors/unauthorized',
};

/** A router that answers each post as the profile does, and records what was posted. */
function router(flash: Readonly<Record<string, unknown>>, props: object) {
  const posts: { readonly url: string; readonly data: Readonly<Record<string, unknown>> }[] = [];
  const listeners = new Set<
    (event: { readonly detail: { readonly page: { readonly props: object } } }) => void
  >();
  const fake: CommandRouter = {
    post: (url, data, options) => {
      posts.push({ url, data });

      for (const listener of listeners) {
        listener({ detail: { page: { props } } });
      }

      options.onFlash(flash);
      options.onFinish();
    },
    on: (_event, callback) => {
      listeners.add(callback);

      return () => listeners.delete(callback);
    },
  };

  return { fake, posts, listeners };
}

describe('the command transport', () => {
  test('posts the envelope and the document, and answers with the receipt and the problem', async () => {
    const { fake, posts, listeners } = router({ receipt: RECEIPT }, { problem: PROBLEM });
    const answer = await inertiaCommands(
      fake,
      '/cms/commands/',
      () => 'key-1',
    )({
      command: 'grant.assign@1',
      document: { role: 'r' },
      options: { dryRun: true, waitLevel: 'origin' },
    });

    expect(posts).toEqual([
      {
        url: '/cms/commands/grant.assign/v1',
        data: {
          envelope: { dry_run: true, idempotency_key: 'key-1', wait_level: 'origin' },
          command: { role: 'r' },
        },
      },
    ]);
    expect(answer).toEqual({ receipt: RECEIPT, problem: PROBLEM });
    expect(listeners.size).toBe(0);
  });

  test('refuses an answer without a receipt and a command that is no name and version', async () => {
    const { fake } = router({}, {});

    await expect(
      inertiaCommands(
        fake,
        '/cms/commands',
      )({ command: 'grant.assign@1', document: {}, options: {} }),
    ).rejects.toBeInstanceOf(CommandUnanswered);
    expect(() => commandUrl('/cms/commands', 'grant.assign')).toThrow(CommandUnanswered);
  });
});
