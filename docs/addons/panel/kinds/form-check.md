---
title: Form check
weight: 27
description: "A form check: a pure function of a command's draft to issues, run on every edit, which can block the submit only when it mirrors a hook of its addon on the same command."
---

# Form check

A form check reads the draft of a command as the viewer edits it and answers with issues: information, a warning, something to acknowledge, or an error that blocks the submit. The server stays the authority: after a submit, the kernel's errors replace the checks' issues.

| | |
|---|---|
| Manifest | `new FormCheck($id, 'command.form.checks@1', '<command>@<version>', Severity::Warning, mirrors: <hook class>)` |
| Bundle | a `FormCheck<D>` of `@cboxdk/cms-panel/extend`, registered as the function itself |
| Receives | the command's document, frozen, and a `CheckContext` with the locale and the viewer's actor id |
| Gives | a list of `Issue`: path, code in the addon's namespace, severity, message key and parameters |
| Scaffold | `cms:make:panel check <namespace> <id> --command=<name>@<version> --severity=<severity>` |
| Test | `expectFormCheckContract()`, and `checkParity()` for a mirrored check |
| Points | [`command.form.checks@1`](../points/command-form-checks.md) |

## The mirror rule

A check with severity `Error` blocks the submit, so it must mirror a `ValidateHook` or `AuthorizeHook` of its own addon on the same command, named in `mirrors`; `cms:build` refuses another with `registry_panel_check_unmirrored`. At run time the host weighs every issue down to the severity the manifest declares, so a check without a mirror cannot block, whatever it answers. The check and its hook must agree, which `checkParity()` holds ([add a form check mirrored by a hook](../../../recipes/panel-check.md)).

## Rules

- A check is synchronous and answers within 16 ms; one that throws or runs over is skipped for the rest of the session.
- It runs on a draft that may still lack required members, so it guards what it reads.
- An issue's code is in the addon's namespace, or the host refuses it.

## Example

The addon `acme/cms-notes` warns about a long title:

<!-- example: examples/Vitest/Panel/Kinds/form-check.test.ts -->
```ts
// A form check contribution, as cms:make:panel check scaffolds it: the addon acme/cms-notes checks
// the draft of its command notes.create as the viewer edits it. A check is a pure function of the
// document to issues, synchronous and within 16 ms, each issue at the path of its value with a
// code in the addon's namespace. This one only warns, so it needs no mirrored hook; a check that
// blocks with an error must mirror a ValidateHook or AuthorizeHook of the addon on the same
// command. expectFormCheckContract() runs it twice on each document within the host's budget.

import { definePanelAddon, type FormCheck } from '@cboxdk/cms-panel/extend';
import { expectFormCheckContract } from '@cboxdk/cms-panel/testing';
import { expect, test } from 'vitest';

interface NotesCreateV1 {
  readonly title?: string;
}

/** Warns about a title of more than 80 characters, which lists cut short. */
const longTitle: FormCheck<NotesCreateV1> = (document) =>
  (document.title?.length ?? 0) > 80
    ? [
        {
          path: 'title',
          code: 'notes.long_title',
          severity: 'warning',
          message: 'notes.long_title.message',
          parameters: { length: document.title?.length ?? 0 },
        },
      ]
    : [];

const addon = definePanelAddon({ 'notes.long-title': longTitle });

test('notes.long-title keeps the form check contract and warns at the title', () => {
  const issues = expectFormCheckContract({
    addon,
    id: 'notes.long-title',
    namespace: 'notes',
    severity: 'warning',
    documents: [{ title: 'Field notes' }, { title: 'x'.repeat(81) }, {}],
  });

  expect(issues).toEqual([
    [],
    [
      {
        path: 'title',
        code: 'notes.long_title',
        severity: 'warning',
        message: 'notes.long_title.message',
        parameters: { length: 81 },
      },
    ],
    [],
  ]);
});
```
