# Contract examples

Running examples for the documentation pages that run a shared contract suite of the testkit against an implementation, in `examples/Contract/<Topic>/`. The `Contract` suite of `phpunit.xml` runs them in gate 5, and `composer docs:check` requires that a page embeds each `*Test.php` here. The rules for an example are in `Cbox\Cms\Tooling\Docs\Domain\DocsAudit`.
