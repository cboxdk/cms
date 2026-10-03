// @cboxdk/cms-panel/ui: the component kit's stable API (decision D1 of the panel extension
// architecture). An addon reaches the kit only through the SDK, and the panel's import map hands
// it the panel's own copy at run time, so an addon never bundles a second kit. The kit's
// experimental components are in @cboxdk/cms-panel/experimental until they are promoted.

export * from './kit/stable';
