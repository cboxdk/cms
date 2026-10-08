---
title: Decorator
weight: 25
description: "A decorator: a function of a point's props that adds content and a badge around a default it never receives, and tightens only the props its manifest declares."
---

# Decorator

A decorator adds to a default the page renders, such as the submit of a form, without taking its place. It is a function of the point's props that never receives the default, so it cannot drop it.

| | |
|---|---|
| Manifest | `new DecoratorContribution($id, '<point>@<version>', [Tighten::Description], mirrors: <hook class>)` |
| Bundle | a `Decorator<P, T>` of `@cboxdk/cms-panel/extend`, registered as the function itself |
| Receives | the point's props, frozen |
| Gives | a `Decoration<T>`: content `before` and `after` the default, a `badge`, and `tighten`, the props it tightens |
| Scaffold | none; `cms:make:addon-ui` writes a note for it |
| Test | `expectDecoratorKeepsDefault()` |
| Points | [`command.form.submit@1`](../points/command-form-submit.md), [`command.form.receipt@1`](../points/command-form-receipt.md) |

## Rules

- A point declares what a decorator may tighten (`Tighten::DisabledReason`, `Description`, `ToneTowardsDanger`), and a contribution declares the subset it uses; `cms:build` refuses more with `registry_panel_tightening_undeclared`, and the host passes over and reports a tightening the manifest does not declare.
- Tightening only tightens: a disabled reason only disables, a description is only appended, and a tone only moves towards warning or danger. Several decorators combine most restrictively.
- A disabled reason blocks a run, so a decorator that tightens it must mirror a `ValidateHook` or `AuthorizeHook` of its addon on the one command it is scoped to (`registry_panel_check_unmirrored`).
- The host renders the default once, whatever a decorator answers or throws.

## Example

The addon `acme/cms-notes` disables a run while a note is locked, as its hook does on the server:

<!-- example: examples/Vitest/Panel/Kinds/decorator.test.tsx -->
```tsx
// @vitest-environment jsdom

// A decorator contribution: the addon acme/cms-notes decorates the submit of a command form. It is a
// function of the point's props that never receives the default, so it cannot remove it: it adds a
// badge and tightens the one prop its manifest declares, the disabled reason, while a note is
// locked. A disabled reason blocks the run, so the manifest's DecoratorContribution mirrors the
// addon's hook that refuses the same command on the server. expectDecoratorKeepsDefault() renders
// the default once with the decoration and refuses a tightening the manifest does not declare.

import { definePanelAddon, type Decorator } from '@cboxdk/cms-panel/extend';
import { expectDecoratorKeepsDefault } from '@cboxdk/cms-panel/testing';
import { expect, test } from 'vitest';

interface SubmitProps {
  readonly command: string;
  readonly version: number;
  readonly title: string;
}

/** Disables the run of notes.lock.release while the note is locked, as the hook does. */
const lockedNote: Decorator<SubmitProps, 'disabled_reason'> = (props) =>
  props.command === 'notes.lock.release'
    ? {
        badge: { tone: 'warning', label: 'notes.locked.badge' },
        tighten: { disabled_reason: 'notes.locked.reason' },
      }
    : {};

const addon = definePanelAddon({ 'notes.locked': lockedNote });

test('notes.locked keeps the default and tightens only the disabled reason', async () => {
  const rendered = await expectDecoratorKeepsDefault({
    addon,
    id: 'notes.locked',
    props: { command: 'notes.lock.release', version: 1, title: 'Release the lock' },
    tightens: ['disabled_reason'],
    host: {
      namespace: 'notes',
      texts: { 'notes.locked.reason': 'The note is locked by another editor.' },
    },
  });

  expect(rendered.tightened.disabled).toBe(true);
  expect(rendered.tightened.disabledReasons).toEqual(['The note is locked by another editor.']);
  expect(rendered.badges).toEqual([{ tone: 'warning', label: 'notes.locked.badge' }]);
  await rendered.unmount();
});
```
