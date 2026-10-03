// The props a kit component passes on to a React Aria hook or component, without the ones the
// caller left undefined: with exactOptionalPropertyTypes, an optional prop may be left out but not
// given as undefined, and the kit's own props allow undefined so a caller can pass a value through.

export type Defined<T> = { [K in keyof T]?: Exclude<T[K], undefined> };

export function defined<T extends object>(props: T): Defined<T> {
  const result: Record<string, unknown> = {};

  for (const [key, value] of Object.entries(props)) {
    if (value !== undefined) {
      result[key] = value;
    }
  }

  return result as Defined<T>;
}
