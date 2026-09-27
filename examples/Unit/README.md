# Unit examples

Running examples for the documentation pages that need no services, in `examples/Unit/<Topic>/`. The `Unit` suite of `phpunit.xml` runs them in gate 5, and `composer docs:check` requires that a page embeds each `*Test.php` here. The rules for an example are in `Cbox\Cms\Tooling\Docs\Domain\DocsAudit`.
