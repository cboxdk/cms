import { a as slugIn, i as isArticle, n as SLUG_PATH, o as slugOf, r as TITLE_PATH, s as titleOf, t as SHAPE } from "./slug-OJHt0rsu.js";
import { definePanelAddon } from "@cboxdk/cms-panel/extend";
//#region workbench/addons/fixtureaddon/resources/panel/src/checks.ts
var slugHint = (document) => {
	if (!isArticle(document) || slugIn(document) !== void 0) return [];
	const title = titleOf(document);
	return slugOf(title ?? "") === null ? [{
		path: TITLE_PATH,
		code: "fixtureaddon.slug_hint",
		severity: "warning",
		message: "fixtureaddon.slug_hint.message"
	}] : [];
};
var slugOverride = (document) => {
	const slug = slugIn(document);
	if (!isArticle(document) || slug === void 0) return [];
	const derived = slugOf(titleOf(document) ?? "");
	return derived === slug ? [] : [{
		path: SLUG_PATH,
		code: "fixtureaddon.slug_override",
		severity: "acknowledge",
		message: "fixtureaddon.slug_override.message",
		parameters: { derived: derived ?? "" }
	}];
};
var slugShape = (document) => {
	const slug = slugIn(document);
	if (!isArticle(document) || slug === void 0 || SHAPE.test(slug)) return [];
	return [{
		path: SLUG_PATH,
		code: "fixtureaddon.slug_shape",
		severity: "error",
		message: "fixtureaddon.slug_shape.message",
		parameters: { slug }
	}];
};
//#endregion
//#region workbench/addons/fixtureaddon/resources/panel/src/panel.ts
var panel_default = definePanelAddon({
	"fixtureaddon.slug-hint": slugHint,
	"fixtureaddon.slug-override": slugOverride,
	"fixtureaddon.slug-shape": slugShape,
	"fixtureaddon.slug-review": () => import("./SlugReview-BlvZkDzn.js")
});
//#endregion
export { panel_default as default };
