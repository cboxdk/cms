---
title: Fixture addon
---

# Fixture addon

The documentation of the workbench's fixture addon, cboxdk/cms-fixture-addon, in the namespace fixtureaddon. It extends the workbench's type app:fixture_article with the field ext.fixtureaddon.fixture_slug, derives the slug from the title on entry.create and entry.revise, requires a slug set by hand on entry.create to be well formed (RequireWellFormedSlug), and requires the slug when a revision of the type is released (variant.release). The kernel never requires an extension field itself: the owner's code creates and revises entries without knowing it, so only a validate hook at a release asks for it.

In the panel it contributes to the generic command form of entry.create: the checks fixtureaddon.slug-hint (a warning where the title derives no slug), fixtureaddon.slug-override (an acknowledgement of a slug set by hand) and fixtureaddon.slug-shape (an error for a slug that is not well formed, which mirrors RequireWellFormedSlug, so it may block the submit), and the step fixtureaddon.slug-review before the submit, which shows the slug the article gets and patches it into the draft or stops the run. Their code is the panel bundle in dist/panel, built from resources/panel and signed with the test key panel-signing-test-key.pem; the workbench trusts that key's public key in cbox-cms.addons.publishers, so cms:build verifies the signature as it does for any addon. resources/panel/parity/slug-shape.json holds the mirrored check and its hook to the same verdicts.
