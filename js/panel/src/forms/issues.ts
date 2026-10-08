// The issues of a command form (section 3.7 of the panel extension architecture): what the checks
// of command.form.checks@1 found, as the form shows them. An issue of severity error is shown at
// its field as the field's error, beside the issues of the generated validator, which come first,
// because the schema is the contract; the other issues are listed with their field, an issue of
// severity acknowledge with the tick that lets the submit go on. After a submit, the kernel's
// errors at their paths replace the checks' issues there: the server is the authority.

import type { FormMember, FormModel, SchemaFormTexts } from '@cboxdk/cms-ui-kit';

import type { FoundIssue } from '../host/checks';
import { fieldPathText, isItemKey, pathSegments } from './field-path';

/** The field errors to show: the validator's issue at a path, else the check's error there. */
export function fieldErrors(
  validator: Readonly<Record<string, string>>,
  blocking: readonly FoundIssue[],
  text: (issue: FoundIssue) => string,
): Readonly<Record<string, string>> {
  const errors: Record<string, string> = {};

  for (const issue of blocking) {
    errors[issue.path] ??= text(issue);
  }

  return { ...errors, ...validator };
}

/**
 * The errors at the fields the form has: an error at a path below a member the form edits as one
 * control, such as a field of a revision below the fields member edited as JSON, is shown at that
 * control, with the rest of the path before its message, so no error is lost where the form has no
 * control for its exact path. An error at a path the form has nothing for at all stays at its
 * path, where the error summary still lists it.
 */
export function atControls(
  errors: Readonly<Record<string, string>>,
  model: FormModel,
): Readonly<Record<string, string>> {
  const shown: Record<string, string> = {};

  for (const [path, message] of Object.entries(errors)) {
    const control = controlAt(model, path);

    if (control === undefined || control === path) {
      shown[path] ??= message;
    } else {
      shown[control] ??= `${path.slice(control.length + 1)}: ${message}`;
    }
  }

  return shown;
}

/** The path of the control the error at the path belongs to: the deepest member on the path the form renders as a control. */
function controlAt(model: FormModel, path: string): string | undefined {
  const segments = pathSegments(path);

  for (let length = segments.length; length > 0; length -= 1) {
    const candidate = fieldPathText(segments.slice(0, length));
    const member = memberAt(model, candidate);

    if (member !== undefined && (length === segments.length || member.shape.kind === 'fields')) {
      return candidate;
    }
  }

  return undefined;
}

/**
 * The issues without those at a path the server answered an error for: the server's error
 * replaces them there.
 */
export function withoutServerPaths<I extends { readonly path: string }>(
  issues: readonly I[],
  server: Readonly<Record<string, string>>,
): readonly I[] {
  return issues.filter((issue) => !Object.hasOwn(server, issue.path));
}

/**
 * The member of the model at the path, or undefined when the model has none there. A segment that
 * is a list index or the key of one item of a list goes to the member of the list's items.
 */
export function memberAt(model: FormModel, path: string): FormMember | undefined {
  let members: readonly FormMember[] = model.root.members;
  let found: FormMember | undefined;

  for (const segment of pathSegments(path)) {
    if (typeof segment === 'number' || isItemKey(segment)) {
      if (found?.shape.kind !== 'list') {
        return undefined;
      }

      found = { ...found, shape: found.shape.item };
      members = found.shape.kind === 'object' ? found.shape.members : [];

      continue;
    }

    found = members.find((member) => member.key === segment);

    if (found === undefined) {
      return undefined;
    }

    members = found.shape.kind === 'object' ? found.shape.members : [];
  }

  return found;
}

/** The label of the field at the path, as the form shows it: the catalogue's, the schema's or the path. */
export function labelAt(model: FormModel, path: string, texts: SchemaFormTexts): string {
  const member = memberAt(model, path);
  const keys = pathSegments(path).filter(
    (segment): segment is string => typeof segment === 'string',
  );

  return member === undefined ? path : texts.label(keys, member.title ?? member.key);
}
