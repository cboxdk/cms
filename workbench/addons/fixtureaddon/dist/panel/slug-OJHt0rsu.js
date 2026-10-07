//#region workbench/addons/fixtureaddon/resources/panel/src/slug.ts
/** The type id of app:fixture_article, as FixtureArticle::TYPE_ID names it. */
var ARTICLE_TYPE = "01a0df3e-8cef-7e9f-8daf-9faa60f1faa6";
/** The path of the addon's field in a command document, as FieldPath::toString() writes it. */
var SLUG_PATH = "fields.ext.fixtureaddon.fixture_slug";
/** The path of the owner's title, which the slug is derived from. */
var TITLE_PATH = "fields.fixture_title";
/** A well-formed slug, as RequireWellFormedSlug::SHAPE requires it. */
var SHAPE = /^[a-z0-9]+(-[a-z0-9]+)*$/;
/**
* The slug of a title, as DeriveSlug::slugOf() derives it: lower case, every run of other
* characters than a to z and 0 to 9 as one hyphen, without hyphens at its ends, and at most
* MAX_LENGTH characters; null for a title with no letter or digit.
*/
function slugOf(title) {
	const slug = trimHyphens(trimHyphens(title.toLowerCase().replaceAll(/[^a-z0-9]+/g, "-")).slice(0, 120));
	return slug === "" ? null : slug;
}
function trimHyphens(text) {
	return text.replaceAll(/^-+|-+$/g, "");
}
/** Whether the document creates an entry of the addon's type. */
function isArticle(document) {
	return document.type === ARTICLE_TYPE;
}
/** The owner's title of the document, or undefined when it has none. */
function titleOf(document) {
	const title = document.fields?.fixture_title;
	return typeof title === "string" ? title : void 0;
}
/** The addon's slug of the document, or undefined when it sets none. */
function slugIn(document) {
	const own = document.fields?.ext?.fixtureaddon;
	const slug = own === void 0 ? void 0 : own.fixture_slug;
	return typeof slug === "string" ? slug : void 0;
}
//#endregion
export { slugIn as a, isArticle as i, SLUG_PATH as n, slugOf as o, TITLE_PATH as r, titleOf as s, SHAPE as t };
