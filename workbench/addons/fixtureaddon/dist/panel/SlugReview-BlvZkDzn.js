import { a as slugIn, i as isArticle, n as SLUG_PATH, o as slugOf, s as titleOf } from "./slug-OJHt0rsu.js";
import { usePanelHost } from "@cboxdk/cms-panel/extend";
import { Button, Callout, Inline, Stack } from "@cboxdk/cms-panel/experimental";
import { jsx, jsxs } from "react/jsx-runtime";
//#region workbench/addons/fixtureaddon/resources/panel/src/SlugReview.tsx
function SlugReview({ draft, patch, next, cancel }) {
	const { t } = usePanelHost();
	const article = isArticle(draft);
	const slug = slugIn(draft) ?? slugOf(titleOf(draft) ?? "");
	return /* @__PURE__ */ jsx(Callout, {
		tone: "info",
		title: t(article ? "fixtureaddon.slug_review.title" : "fixtureaddon.slug_review.other_type"),
		children: /* @__PURE__ */ jsxs(Stack, {
			gap: "sm",
			children: [article ? /* @__PURE__ */ jsx("p", {
				"data-fixtureaddon-slug": slug ?? "",
				children: slug === null ? t("fixtureaddon.slug_review.none") : t("fixtureaddon.slug_review.slug", { slug })
			}) : null, /* @__PURE__ */ jsxs(Inline, {
				gap: "sm",
				children: [/* @__PURE__ */ jsx(Button, {
					type: "button",
					variant: "primary",
					onClick: () => {
						if (article && slug !== null && slugIn(draft) === void 0) patch(SLUG_PATH, slug);
						next();
					},
					children: t("fixtureaddon.slug_review.use")
				}), /* @__PURE__ */ jsx(Button, {
					type: "button",
					variant: "quiet",
					onClick: () => {
						cancel("fixtureaddon.slug_review.cancelled");
					},
					children: t("fixtureaddon.slug_review.stop")
				})]
			})]
		})
	});
}
//#endregion
export { SlugReview as default };
