import { t as activity } from "./activity-MsooxUr4.js";
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
/** The path of the grantee in a grant.assign document, as FieldPath::toString() writes it. */
var ACTOR_PATH = "actor";
var selfGrant = (document, context) => {
	const actor = document.actor;
	if (typeof actor !== "string" || context.viewer === null || actor.toLowerCase() !== context.viewer.toLowerCase()) return [];
	return [{
		path: ACTOR_PATH,
		code: "fixtureaddon.self_grant",
		severity: "error",
		message: "fixtureaddon.self_grant.message"
	}];
};
//#endregion
//#region workbench/addons/fixtureaddon/resources/panel/src/decorators.ts
var submitNote = () => ({ tighten: { description: "fixtureaddon.submit_note.description" } });
var receiptNote = (props) => ({ badge: {
	tone: props.problem === null ? "info" : "warning",
	label: props.problem === null ? "fixtureaddon.receipt_note.saved" : "fixtureaddon.receipt_note.refused"
} });
//#endregion
//#region workbench/addons/fixtureaddon/resources/panel/src/panel.ts
var panel_default = definePanelAddon({
	"fixtureaddon.activity": activity,
	"fixtureaddon.articles": () => import("./Articles-KaCkQHVr.js"),
	"fixtureaddon.articles-permission": () => import("./notes-BjP178BS.js").then((module) => ({ default: module.ArticlesPermissionNote })),
	"fixtureaddon.dry-run-note": () => import("./DryRunNote-CH_7s8Uw.js"),
	"fixtureaddon.faulty": () => import("./Faulty-DcIo0aFr.js"),
	"fixtureaddon.four-eyes": () => import("./FourEyes-tbv9mWVy.js"),
	"fixtureaddon.four-eyes-note": () => import("./notes-BjP178BS.js").then((module) => ({ default: module.FourEyesNote })),
	"fixtureaddon.my-articles": () => import("./MyArticles-BQ0aUbk3.js"),
	"fixtureaddon.receipt-note": receiptNote,
	"fixtureaddon.recent-activity": () => import("./RecentActivity-BnsUyLFv.js"),
	"fixtureaddon.self-grant": selfGrant,
	"fixtureaddon.slug-help": () => import("./SlugHelp-DHkd5cZb.js"),
	"fixtureaddon.slug-hint": slugHint,
	"fixtureaddon.slug-input": () => import("./SlugInput-Fm21rVD_.js"),
	"fixtureaddon.slug-override": slugOverride,
	"fixtureaddon.slug-review": () => import("./SlugReview-BlvZkDzn.js"),
	"fixtureaddon.slug-shape": slugShape,
	"fixtureaddon.submit-note": submitNote
});
//#endregion
export { panel_default as default };
