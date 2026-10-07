//#region workbench/addons/fixtureaddon/resources/panel/src/Faulty.tsx
/** The section's component: a slot component of account.me.sections@1 that reads none of its props. */
function Faulty() {
	throw new Error("fixtureaddon.faulty throws on purpose: it shows that one failing contribution never blanks a page.");
}
//#endregion
export { Faulty as default };
