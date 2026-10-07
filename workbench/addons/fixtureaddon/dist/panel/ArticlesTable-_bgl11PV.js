import { usePanelHost } from "@cboxdk/cms-panel/extend";
import { DataTable, EmptyState } from "@cboxdk/cms-panel/experimental";
import { jsx } from "react/jsx-runtime";
//#region workbench/addons/fixtureaddon/resources/panel/src/article-rows.ts
/** Whether any article of the result carries a title, which only a reader above public gets. */
function hasTitles(result) {
	return result.articles.some((article) => typeof article.title === "string");
}
//#endregion
//#region workbench/addons/fixtureaddon/resources/panel/src/ArticlesTable.tsx
function ArticlesTable({ result, headingLevel }) {
	const { t } = usePanelHost();
	const titles = hasTitles(result);
	return /* @__PURE__ */ jsx(DataTable, {
		label: t("fixtureaddon.articles.table"),
		columns: [
			{
				id: "slug",
				title: t("fixtureaddon.articles.slug"),
				rowHeader: true,
				render: (article) => article.slug === null ? t("fixtureaddon.articles.no_slug") : article.slug
			},
			{
				id: "entry",
				title: t("fixtureaddon.articles.entry"),
				render: (article) => /* @__PURE__ */ jsx("code", { children: article.entry })
			},
			...titles ? [{
				id: "title",
				title: t("fixtureaddon.articles.title"),
				render: (article) => article.title ?? ""
			}] : []
		],
		rows: result.articles,
		rowKey: (article) => article.entry,
		empty: /* @__PURE__ */ jsx(EmptyState, {
			title: t("fixtureaddon.articles.none_title"),
			description: t("fixtureaddon.articles.none_body"),
			headingLevel
		})
	});
}
/** The table for the state of the data, as the panel hands it over. */
function ArticlesData({ data, headingLevel }) {
	const { t } = usePanelHost();
	if (data.status === "loading") return /* @__PURE__ */ jsx("p", { children: t("fixtureaddon.articles.loading") });
	if (data.status === "failed") return /* @__PURE__ */ jsx("p", { children: t("fixtureaddon.articles.failed", { code: data.code }) });
	return /* @__PURE__ */ jsx(ArticlesTable, {
		result: data.value,
		headingLevel
	});
}
//#endregion
export { ArticlesData as t };
