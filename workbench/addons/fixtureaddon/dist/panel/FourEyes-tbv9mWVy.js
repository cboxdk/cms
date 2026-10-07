import { usePanelHost } from "@cboxdk/cms-panel/extend";
import { Button, Callout, Inline, Stack } from "@cboxdk/cms-panel/experimental";
import { jsx, jsxs } from "react/jsx-runtime";
//#region workbench/addons/fixtureaddon/resources/panel/src/FourEyes.tsx
function FourEyes({ draft, next, cancel }) {
	const { t } = usePanelHost();
	const actor = draft.actor;
	return /* @__PURE__ */ jsx(Callout, {
		tone: "warning",
		title: t("fixtureaddon.four_eyes.title"),
		children: /* @__PURE__ */ jsxs(Stack, {
			gap: "sm",
			children: [
				/* @__PURE__ */ jsx("p", {
					"data-fixtureaddon-grantee": actor ?? "",
					children: typeof actor === "string" ? t("fixtureaddon.four_eyes.grantee", { actor }) : t("fixtureaddon.four_eyes.no_grantee")
				}),
				/* @__PURE__ */ jsx("p", { children: t("fixtureaddon.four_eyes.body") }),
				/* @__PURE__ */ jsxs(Inline, {
					gap: "sm",
					children: [/* @__PURE__ */ jsx(Button, {
						type: "button",
						variant: "primary",
						onClick: next,
						children: t("fixtureaddon.four_eyes.reviewed")
					}), /* @__PURE__ */ jsx(Button, {
						type: "button",
						variant: "quiet",
						onClick: () => {
							cancel("fixtureaddon.four_eyes.cancelled");
						},
						children: t("fixtureaddon.four_eyes.stop")
					})]
				})
			]
		})
	});
}
//#endregion
export { FourEyes as default };
