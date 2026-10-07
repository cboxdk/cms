//#region workbench/addons/fixtureaddon/resources/panel/src/activity.ts
/** The key of the record in the session storage. */
var KEY = "fixtureaddon.activity";
var OUTCOMES = [
	"rejected",
	"committed",
	"committed_wait_timeout",
	"dry_run"
];
var activity = (event) => {
	const record = {
		command: event.command,
		version: event.version,
		outcome: event.outcome,
		changeset: event.changeset
	};
	window.sessionStorage.setItem(KEY, JSON.stringify(record));
};
/** The record the storage holds, or null when it holds none or one that is not of this form. */
function readActivity() {
	const text = window.sessionStorage.getItem(KEY);
	if (text === null) return null;
	let parsed;
	try {
		parsed = JSON.parse(text);
	} catch {
		return null;
	}
	if (typeof parsed !== "object" || parsed === null) return null;
	const record = parsed;
	if (typeof record.command !== "string" || typeof record.version !== "number" || typeof record.outcome !== "string" || !OUTCOMES.includes(record.outcome) || record.changeset !== null && typeof record.changeset !== "string") return null;
	return {
		command: record.command,
		version: record.version,
		outcome: record.outcome,
		changeset: record.changeset
	};
}
//#endregion
export { readActivity as n, activity as t };
