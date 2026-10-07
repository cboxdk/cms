import { n as readActivity } from "./activity-MsooxUr4.js";
import { usePanelHost } from "@cboxdk/cms-panel/extend";
import { Badge, DescriptionList, EmptyState, Section } from "@cboxdk/cms-panel/experimental";
import { jsx } from "react/jsx-runtime";
//#region workbench/addons/fixtureaddon/resources/panel/src/RecentActivity.tsx
/** The section's component: it reads none of the point's props, the viewer's actor id. */
function RecentActivity() {
	const { t } = usePanelHost();
	const record = readActivity();
	return /* @__PURE__ */ jsx(Section, {
		title: t("fixtureaddon.recent_activity.title"),
		children: record === null ? /* @__PURE__ */ jsx(EmptyState, {
			title: t("fixtureaddon.recent_activity.none_title"),
			description: t("fixtureaddon.recent_activity.none_body"),
			headingLevel: 3
		}) : /* @__PURE__ */ jsx(DescriptionList, { items: [
			{
				id: "command",
				term: t("fixtureaddon.recent_activity.command"),
				description: /* @__PURE__ */ jsx("code", { children: `${record.command}@${String(record.version)}` })
			},
			{
				id: "outcome",
				term: t("fixtureaddon.recent_activity.outcome"),
				description: /* @__PURE__ */ jsx(Badge, {
					tone: record.outcome === "rejected" ? "warning" : "success",
					children: t(`fixtureaddon.recent_activity.outcome.${record.outcome}`)
				})
			},
			{
				id: "changeset",
				term: t("fixtureaddon.recent_activity.changeset"),
				description: record.changeset === null ? t("fixtureaddon.recent_activity.no_changeset") : /* @__PURE__ */ jsx("code", { children: record.changeset })
			}
		] })
	});
}
//#endregion
export { RecentActivity as default };
