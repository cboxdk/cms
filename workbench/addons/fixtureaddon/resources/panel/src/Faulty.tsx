// The fixture addon's section of the who-am-I page that throws when it renders (section 5.4 of
// the panel extension architecture): it exists so that the panel's isolation of a failing
// contribution can be seen and tested, and the workbench disables it through the kill switch,
// cbox-cms.panel.disabled, so a person using the workbench never sees it. A browser test enables
// it to prove that one failing contribution never blanks a page.

/** The section's component: a slot component of account.me.sections@1 that reads none of its props. */
export default function Faulty(): never {
  throw new Error('fixtureaddon.faulty throws on purpose: it shows that one failing contribution never blanks a page.');
}
