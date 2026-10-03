// What a module an addon may not import does instead (PRD 13.4): the panel's import map maps the
// module, inside the scope of every addon, to an entry that calls refuse() before anything of the
// addon runs, so the addon fails at once with an error that names the module and says why.
// docs/developers/panel.md describes the shared modules and this error.

/** The error an addon gets for importing a module of the panel that is not addon API. */
export class PanelImportRefused extends Error {
  override readonly name = 'PanelImportRefused';

  constructor(readonly specifier: string) {
    super(
      `Cbox CMS panel: an addon may not import ${specifier}. The panel's router, page state and UI primitives are not addon API; an addon shares only the modules of the panel's import map (docs/developers/panel.md#shared-modules).`,
    );
  }
}

export function refuse(specifier: string): never {
  throw new PanelImportRefused(specifier);
}
