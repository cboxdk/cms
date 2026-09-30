---
title: Fixture addon
---

# Fixture addon

The documentation of the workbench's fixture addon, cboxdk/cms-fixture-addon, in the namespace fixtureaddon. It extends the workbench's type app:fixture_article with the field ext.fixtureaddon.fixture_slug, derives the slug from the title on entry.create and entry.revise, and requires it when a revision of the type is released (variant.release). The kernel never requires an extension field itself: the owner's code creates and revises entries without knowing it, so only a validate hook at a release asks for it.
