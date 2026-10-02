---
title: Contracts
weight: 31
description: The contracts of the kernel, their default implementations, their fakes and their shared suites, and how an application replaces one.
---

# Contracts

A contract is an interface in the contracts module of `cboxdk/cms`, `Cbox\Cms\Contracts`, which depends only on PHP. The kernel asks the container for the contract, never for a class. `CoreServiceProvider` binds each contract as a singleton to the class named in `cbox-cms.contracts`, and an application replaces one entry at a time in its own `config/cbox-cms.php`; see [Configuration](../../developers/configuration.md#contracts).

Every contract has two things in the testkit, `Cbox\Cms\Testkit`: a fake for tests of code that uses the contract, and a shared suite, a trait, that every implementation runs in a PHPUnit class in its package's `tests/Contract` directory. The default implementation and the fake run the same suite, so a replacement that passes it keeps the same promises.

| Contract | Default | Fake | Shared suite |
|---|---|---|---|
| [`Clock`](clock.md) | `SystemClock` | `FakeClock` | `ClockContract` |
| [`IdGenerator`](id-generator.md) | `SystemIdGenerator` | `FakeIdGenerator` | `IdGeneratorContract` |
| [`ReceiptStore`](receipt-store.md) | `PostgresReceiptStore` | `FakeReceiptStore` | `ReceiptStoreContract` |
| [`IdempotencyStore`](idempotency-store.md) | `PostgresIdempotencyStore` | `FakeIdempotencyStore` | `IdempotencyStoreContract` |
| [`ActorDirectory`](actor-directory.md) | `PostgresActorDirectory` | `FakeIdentity` | `ActorDirectoryContract` |
| [`CredentialVerifier`](credential-verifier.md) | `PostgresCredentialVerifier` | `FakeIdentity` | `CredentialVerifierContract` |
| [`FragmentStore`](fragment-store.md) | `ValkeyFragmentStore` | `FakeFragmentStore` | `FragmentStoreContract` |
| [`CdnDriver`](cdn-driver.md) | none until full-scale invalidation | `FakeCdnDriver` | `CdnDriverContract` |
| [`LoginConnection`](login-connection.md) | none until the identity module's connections | `FakeLoginConnection` | `LoginConnectionContract` |
| [`IssuerResolver`](issuer-resolver.md) | none until the OpenID Connect implementation | `FakeIssuerResolver` | `IssuerResolverContract` |
| [`TypeTableReader`](type-table-reader.md) | `PostgresTypeTableReader` | `FakeTypeTableReader` | `TypeTableReaderContract` |
| [`Telemetry`](telemetry.md) | `LogTelemetry` | `FakeTelemetry` | `TelemetryContract` |
| [`TypeCatalog`](type-catalog.md) | the generated `GeneratedTypeCatalog` | `FakeTypeCatalog` | `TypeCatalogContract` |
| [`RecordCodecs`](record-codecs.md) | the generated `GeneratedRecordCodecs` | `FakeRecordCodecs` | `RecordCodecsContract` |

`TypeCatalog` and `RecordCodecs` have no entry in `cbox-cms.contracts`: the kernel cannot name the application's generated classes, so the service provider that `cms:generate` writes binds them (GUARDRAILS 2.4).

The contract of a doctor check, `DoctorCheck`, is not bound in the container; an application lists its checks by class. It is on [Doctor checks](../doctor-checks.md).
