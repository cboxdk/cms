// The JSON values a contribution receives and sends (GUARDRAILS 2.2): every input of a
// contribution is a JSON document a generated codec wrote, and every command document it issues
// is one the command's codec reads.

/**
 * A JSON value: what JSON.parse gives and JSON.stringify writes without loss.
 *
 * @stable
 */
export type JsonValue = string | number | boolean | null | JsonArray | JsonObject;

/**
 * A JSON array.
 *
 * @stable
 */
export type JsonArray = readonly JsonValue[];

/**
 * A JSON object.
 *
 * @stable
 */
export interface JsonObject {
  readonly [key: string]: JsonValue;
}
