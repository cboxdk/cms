# Postgres examples

Running examples for the documentation pages that need real Postgres and Valkey, in `examples/Postgres/<Topic>/`. The `Postgres` suite of `phpunit.xml` runs them in gate 5 with the testkit's `RealPostgres` and `RealValkey` harnesses, after `composer services:up`, and `composer docs:check` requires that a page embeds each `*Test.php` here. The rules for an example are in `Cbox\Cms\Tooling\Docs\Domain\DocsAudit`.
