import { usePanelHost } from "@cboxdk/cms-panel/extend";
import { Callout } from "@cboxdk/cms-panel/experimental";
import { jsx } from "react/jsx-runtime";
//#region workbench/addons/fixtureaddon/resources/panel/src/notes.tsx
function FourEyesNote() {
	const { t } = usePanelHost();
	return /* @__PURE__ */ jsx(Callout, {
		tone: "info",
		title: t("fixtureaddon.four_eyes_note.title"),
		children: t("fixtureaddon.four_eyes_note.body")
	});
}
function ArticlesPermissionNote() {
	const { t } = usePanelHost();
	return /* @__PURE__ */ jsx(Callout, {
		tone: "info",
		title: t("fixtureaddon.articles_permission.title"),
		children: t("fixtureaddon.articles_permission.body")
	});
}
//#endregion
export { ArticlesPermissionNote, FourEyesNote };
