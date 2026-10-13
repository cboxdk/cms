// The types of the installation as every page behind the login knows them (PRD 11.12, 13.4): the
// prop `types` is the read of type.list as the person, the editor descriptor of each type they may
// see, from which the shell builds the Content section of the navigation, an entry to the content
// list of each type, and the content list shows each entry's type and title field. Nothing here
// names a type or a field (GUARDRAILS 2.4): the types are what the server listed.

import { validateTypesPropV1 } from '../generated/pages/TypesPropV1';
import { validateProblemV1, type ProblemV1 } from '../generated/protocol/ProblemV1';
import { validateTypeEditorV1, type TypeEditorV1 } from '../generated/protocol/TypeEditorV1';
import { validateTypeListV1 } from '../generated/protocol/TypeListV1';

/** What the prop `types` gave: the descriptors, the problem details of a rejected read, or a document the panel cannot read. */
export type TypesState =
  | { readonly status: 'ready'; readonly content: string; readonly types: readonly TypeEditorV1[] }
  | { readonly status: 'rejected'; readonly content: string; readonly problem: ProblemV1 }
  | { readonly status: 'unreadable'; readonly content: string | null };

/** Reads the prop `types` of a page, as the server shared it, checking every descriptor it embeds. */
export function typesOf(prop: unknown): TypesState {
  const checked = validateTypesPropV1(prop);

  if (!checked.valid) {
    return { status: 'unreadable', content: null };
  }

  const { content, result, rejection } = checked.value;

  if (result !== null) {
    const list = validateTypeListV1(result);

    if (!list.valid) {
      return { status: 'unreadable', content };
    }

    const types: TypeEditorV1[] = [];

    for (const type of list.value.types) {
      const editor = validateTypeEditorV1(type);

      if (!editor.valid) {
        return { status: 'unreadable', content };
      }

      types.push(editor.value);
    }

    return { status: 'ready', content, types };
  }

  if (rejection !== null) {
    const problem = validateProblemV1(rejection);

    return problem.valid
      ? { status: 'rejected', content, problem: problem.value }
      : { status: 'unreadable', content };
  }

  return { status: 'unreadable', content };
}

/** The address of the content list of the type, `<content>/<owner>:<handle>`, or across every type for null. */
export function typeListUrl(content: string, type: string | null): string {
  return type === null ? content : `${content}/${type}`;
}

/** The descriptors by type id. */
export function typesById(types: readonly TypeEditorV1[]): ReadonlyMap<string, TypeEditorV1> {
  return new Map(types.map((type) => [type.id, type]));
}
