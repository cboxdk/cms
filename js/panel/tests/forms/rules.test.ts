// The rules the command form checks a document with (GUARDRAILS 2.2, PRD 6.1): read from the
// command's JSON Schema through the kit's model, they give the generated runtime validator the same
// verdict as the validator cms:generate wrote for the command, for every kernel command schema: on
// a document made from the schema's examples, on each member left out, set to null, given a value
// of another kind and, for a list, emptied. The two are made from one schema, so a schema the form
// reads differently from the codec fails here. serverErrors() reads the page's errors prop back by
// the path of each value in the document, and fieldValuesIssue() checks the fields of a revision
// with the runtime's fields rule.

import {
  readCommandSchema,
  type FieldShape,
  type FormMember,
  type JsonObject,
  type JsonValue,
  type ObjectShape,
} from '@cboxdk/cms-ui-kit';
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, test } from 'vitest';

import { validate, type Validation } from '../../src/generated/validation';
import { FORM_PATH, fieldValuesIssue, rulesOf, serverErrors } from '../../src/forms/rules';
import { validateGrantBootstrapRoleV1 } from '../../../../workbench/resources/js/cms/generated/protocol/GrantBootstrapRoleV1';
import { validateActivateActorV1 } from '../../../../workbench/resources/js/cms/generated/protocol/ActivateActorV1';
import { validateDeactivateActorV1 } from '../../../../workbench/resources/js/cms/generated/protocol/DeactivateActorV1';
import { validateRegisterActorV1 } from '../../../../workbench/resources/js/cms/generated/protocol/RegisterActorV1';
import { validateCreateEntryV1 } from '../../../../workbench/resources/js/cms/generated/protocol/CreateEntryV1';
import { validatePublishEntryV1 } from '../../../../workbench/resources/js/cms/generated/protocol/PublishEntryV1';
import { validateReviseEntryV1 } from '../../../../workbench/resources/js/cms/generated/protocol/ReviseEntryV1';
import { validateUnpublishEntryV1 } from '../../../../workbench/resources/js/cms/generated/protocol/UnpublishEntryV1';
import { validateAssignGrantV1 } from '../../../../workbench/resources/js/cms/generated/protocol/AssignGrantV1';
import { validateRevokeGrantV1 } from '../../../../workbench/resources/js/cms/generated/protocol/RevokeGrantV1';
import { validateCreatePlacementV1 } from '../../../../workbench/resources/js/cms/generated/protocol/CreatePlacementV1';
import { validateSetPlacementWindowV1 } from '../../../../workbench/resources/js/cms/generated/protocol/SetPlacementWindowV1';
import { validateCreateRoleV1 } from '../../../../workbench/resources/js/cms/generated/protocol/CreateRoleV1';
import { validateSetRolePermissionsV1 } from '../../../../workbench/resources/js/cms/generated/protocol/SetRolePermissionsV1';
import { validateRegisterSiteV1 } from '../../../../workbench/resources/js/cms/generated/protocol/RegisterSiteV1';
import { validateReleaseVariantV1 } from '../../../../workbench/resources/js/cms/generated/protocol/ReleaseVariantV1';

/** Where the kernel's command schemas are, the files ProtocolSchemas::commands() binds. */
const COMMAND_SCHEMAS = join(
  import.meta.dirname,
  '../../../../packages/core/resources/schemas/commands',
);

type Validator = (value: unknown) => Validation<unknown>;

/** The validator cms:generate wrote for each kernel command schema, by file. */
const GENERATED: Readonly<Record<string, Validator>> = {
  'access.bootstrap.v1.json': validateGrantBootstrapRoleV1,
  'actor.activate.v1.json': validateActivateActorV1,
  'actor.deactivate.v1.json': validateDeactivateActorV1,
  'actor.register.v1.json': validateRegisterActorV1,
  'entry.create.v1.json': validateCreateEntryV1,
  'entry.publish.v1.json': validatePublishEntryV1,
  'entry.revise.v1.json': validateReviseEntryV1,
  'entry.unpublish.v1.json': validateUnpublishEntryV1,
  'grant.assign.v1.json': validateAssignGrantV1,
  'grant.revoke.v1.json': validateRevokeGrantV1,
  'placement.create.v1.json': validateCreatePlacementV1,
  'placement.set_window.v1.json': validateSetPlacementWindowV1,
  'role.create.v1.json': validateCreateRoleV1,
  'role.set_permissions.v1.json': validateSetRolePermissionsV1,
  'site.register.v1.json': validateRegisterSiteV1,
  'variant.release.v1.json': validateReleaseVariantV1,
};

function schemas(): string[] {
  return readdirSync(COMMAND_SCHEMAS)
    .filter((file) => file.endsWith('.json'))
    .sort();
}

function schemaOf(file: string): unknown {
  return JSON.parse(readFileSync(join(COMMAND_SCHEMAS, file), 'utf8'));
}

/** A value every rule of the shape accepts, made from the schema's examples and bounds. */
function sampleOf(shape: FieldShape): JsonValue {
  switch (shape.kind) {
    case 'string':
      if (shape.examples[0] !== undefined) {
        return shape.examples[0];
      }

      return shape.format === 'date-time'
        ? '2026-10-07T09:00:00+02:00'
        : 'a'.repeat(Math.max(shape.minLength ?? 1, 1));
    case 'integer':
      return shape.minimum ?? 0;
    case 'boolean':
      return true;
    case 'enum':
      return shape.values[0] ?? '';
    case 'object':
      return sampleDocument(shape);
    case 'list':
      return Array.from({ length: Math.max(shape.minItems ?? 1, 1) }, () => sampleOf(shape.item));
    case 'fields':
      return { title: 'Launch', ext: { acme: { colour: 'red' } } };
  }
}

/** A document with every member, the required and the optional ones alike. */
function sampleDocument(object: ObjectShape): JsonObject {
  return Object.fromEntries(object.members.map((member) => [member.key, sampleOf(member.shape)]));
}

/** A value of another kind than the shape's. */
function wrongKind(shape: FieldShape): JsonValue {
  return shape.kind === 'integer' || shape.kind === 'boolean' ? 'not that' : 42;
}

/** The verdict as the two validators are compared: whether the value is valid and, if not, where. */
function verdictOf(validation: Validation<unknown>): string {
  return validation.valid ? 'valid' : `invalid at ${validation.issue.path ?? 'the document'}`;
}

/** The documents a member is checked with, each with one thing planted. */
function plantings(document: JsonObject, member: FormMember): [string, JsonObject][] {
  const without: JsonObject = Object.fromEntries(
    Object.entries(document).filter(([key]) => key !== member.key),
  );

  const cases: [string, JsonObject][] = [
    [`${member.key} left out`, without],
    [`${member.key} null`, { ...document, [member.key]: null }],
    [`${member.key} of another kind`, { ...document, [member.key]: wrongKind(member.shape) }],
  ];

  if (member.shape.kind === 'list') {
    cases.push([`${member.key} empty`, { ...document, [member.key]: [] }]);
  }

  if (member.shape.kind === 'object') {
    cases.push([
      `${member.key} with a key it does not have`,
      { ...document, [member.key]: { ...sampleDocument(member.shape), planted: true } },
    ]);
  }

  return cases;
}

describe('the rules of each kernel command schema', () => {
  test('cover every schema with a generated validator', () => {
    expect(Object.keys(GENERATED).sort()).toEqual(schemas());
  });

  for (const file of schemas()) {
    test(`${file} gives the generated validator's verdict`, () => {
      const model = readCommandSchema(schemaOf(file));
      const rules = rulesOf(model);
      const generated = GENERATED[file];

      if (generated === undefined) {
        throw new Error(`No generated validator for ${file}.`);
      }

      const document = sampleDocument(model.root);
      const cases: [string, unknown][] = [
        ['the sample document', document],
        ['the document with a key it does not have', { ...document, planted: true }],
        ['not an object', 'no'],
        ...model.root.members.flatMap((member) => plantings(document, member)),
      ];

      for (const [name, planted] of cases) {
        expect(verdictOf(validate(planted, rules)), name).toBe(verdictOf(generated(planted)));
      }

      expect(validate(document, rules).valid).toBe(true);
    });
  }
});

describe('the errors of a rejected submit', () => {
  test('are read back by the path of each value in the document, the envelope and the document itself at the form', () => {
    expect(
      serverErrors({
        'command.actor': 'Only a pending actor is activated.',
        'command.window.live_from': 'is not a date-time.',
        'command.slugs[0].slug': 'is required.',
        'envelope.wait_level': 'is not one of the wait levels.',
        other: 'left out',
      }),
    ).toEqual({
      actor: 'Only a pending actor is activated.',
      'window.live_from': 'is not a date-time.',
      'slugs[0].slug': 'is required.',
      [FORM_PATH]: 'is not one of the wait levels.',
    });
    expect(serverErrors({ command: 'is not an object' })).toEqual({
      [FORM_PATH]: 'is not an object',
    });
    expect(serverErrors(undefined)).toEqual({});
    expect(serverErrors({ 'command.actor': 4 })).toEqual({});
  });
});

describe('the fields of a revision', () => {
  test("are checked with the runtime's fields rule", () => {
    expect(fieldValuesIssue({ title: 'Launch', ext: { acme: { colour: 'red' } } })).toBeUndefined();
    expect(fieldValuesIssue({ ext: 'none' })).toBeTypeOf('string');
    expect(fieldValuesIssue({ cms_internal: 1 })).toBeTypeOf('string');
    expect(fieldValuesIssue([])).toBeTypeOf('string');
  });
});
