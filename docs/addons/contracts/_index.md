---
title: Contracts
weight: 31
description: The contracts of the kernel, their default implementations, their fakes and their shared suites, and how an application replaces one.
---

# Contracts

A contract is an interface in `cboxdk/cms-contracts`, the one package an addon needs to implement it. The kernel asks the container for the contract, never for a class. `CoreServiceProvider` binds each contract as a singleton to the class named in `cbox-cms.contracts`, and an application replaces one entry at a time in its own `config/cbox-cms.php`; see [Configuration](../../developers/configuration.md#contracts).

Every contract has two things in `cboxdk/cms-testkit`: a fake for tests of code that uses the contract, and a shared suite, a trait, that every implementation runs in a PHPUnit class in its package's `tests/Contract` directory. The default implementation and the fake run the same suite, so a replacement that passes it keeps the same promises.

| Contract | Default | Fake | Shared suite |
|---|---|---|---|
| [`Clock`](clock.md) | `SystemClock` | `FakeClock` | `ClockContract` |
| [`IdGenerator`](id-generator.md) | `SystemIdGenerator` | `FakeIdGenerator` | `IdGeneratorContract` |
| [`ReceiptStore`](receipt-store.md) | `PostgresReceiptStore` | `FakeReceiptStore` | `ReceiptStoreContract` |
| [`IdempotencyStore`](idempotency-store.md) | `PostgresIdempotencyStore` | `FakeIdempotencyStore` | `IdempotencyStoreContract` |

The contract of a doctor check, `DoctorCheck`, is not bound in the container; an application lists its checks by class. It is on [Doctor checks](../doctor-checks.md).
