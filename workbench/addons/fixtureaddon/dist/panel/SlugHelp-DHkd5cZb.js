import { usePanelHost } from "@cboxdk/cms-panel/extend";
import { Callout } from "@cboxdk/cms-panel/experimental";
import { jsx } from "react/jsx-runtime";
//#region workbench/addons/fixtureaddon/resources/panel/src/SlugHelp.tsx
function SlugHelp({ props }) {
	const { t } = usePanelHost();
	return /* @__PURE__ */ jsx(Callout, {
		tone: "info",
		title: t("fixtureaddon.slug_help.title"),
		children: t("fixtureaddon.slug_help.body", { command: props.command })
	});
}
//#endregion
export { SlugHelp as default };
