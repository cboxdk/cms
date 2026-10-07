import { t as ArticlesData } from "./ArticlesTable-_bgl11PV.js";
import { usePanelHost } from "@cboxdk/cms-panel/extend";
import { Section } from "@cboxdk/cms-panel/experimental";
import { jsx } from "react/jsx-runtime";
//#region workbench/addons/fixtureaddon/resources/panel/src/MyArticles.tsx
function MyArticles({ data }) {
	const { t } = usePanelHost();
	return /* @__PURE__ */ jsx(Section, {
		title: t("fixtureaddon.my_articles.title"),
		description: t("fixtureaddon.my_articles.description"),
		children: /* @__PURE__ */ jsx(ArticlesData, {
			data,
			headingLevel: 3
		})
	});
}
//#endregion
export { MyArticles as default };
