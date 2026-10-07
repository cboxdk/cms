import { usePanelHost } from "@cboxdk/cms-panel/extend";
import { Callout } from "@cboxdk/cms-panel/experimental";
import { jsx } from "react/jsx-runtime";
//#region workbench/addons/fixtureaddon/resources/panel/src/DryRunNote.tsx
function DryRunNote({ props }) {
	const { t } = usePanelHost();
	return /* @__PURE__ */ jsx(Callout, {
		tone: "info",
		title: t("fixtureaddon.dry_run_note.title"),
		children: t("fixtureaddon.dry_run_note.body", { command: props.command })
	});
}
//#endregion
export { DryRunNote as default };
