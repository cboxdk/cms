import { t as ArticlesData } from "./ArticlesTable-_bgl11PV.js";
import { usePanelHost } from "@cboxdk/cms-panel/extend";
import { Page, PageHeader } from "@cboxdk/cms-panel/experimental";
import { jsx } from "react/jsx-runtime";
//#region workbench/addons/fixtureaddon/resources/panel/src/Articles.tsx
function Articles({ data }) {
	const { t } = usePanelHost();
	return /* @__PURE__ */ jsx(Page, {
		header: /* @__PURE__ */ jsx(PageHeader, {
			title: t("fixtureaddon.articles.page_title"),
			description: t("fixtureaddon.articles.page_description")
		}),
		children: /* @__PURE__ */ jsx(ArticlesData, {
			data,
			headingLevel: 2
		})
	});
}
//#endregion
export { Articles as default };
