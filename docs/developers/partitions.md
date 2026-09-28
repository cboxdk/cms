---
title: Partitions
weight: 24
description: How the partition manager keeps the range partitions of the kernel's tables, what cms:partitions:maintain does, and how a test covers the dates it writes at.
---

# Partitions

Tables that grow with time are partitioned by range, so old rows go by dropping a whole partition instead of deleting rows (PRD 4, 4.2). The partition manager in `Cbox\Cms\Core\Partitions` keeps them, and `cms:partitions:maintain` runs it.

## The tables

A partitioned table is listed in `cbox-cms.database.partitions.tables` with three settings:

| Setting | Values | Meaning |
|---|---|---|
| `key` | `uuid7` or `timestamp` | The partition key: a column of UUIDv7 ids, or a `timestamptz` column. For `uuid7` the bounds of a span are `Uuid7::lowestAt()` of its first millisecond and of the next span's. |
| `interval` | `day` or `month` | The length of a span. Spans start at midnight UTC. |
| `retention_days` | a whole number, or `null` | How many days after a span ends its partition is removed. `null` keeps every partition. |

The core lists its own tables, and an application adds its tables next to them:

| Table | Key | Interval | Retention |
|---|---|---|---|
| `receipts_standard` | `uuid7` | day | 7 days |
| `receipts_evidence` | `uuid7` | month | kept |
| `receipt_projections_standard` | `uuid7` | day | 7 days |
| `receipt_projections_evidence` | `uuid7` | month | kept |
| `idempotency_keys` | `timestamp` | day | 7 days |

The receipt tables are partitioned by retention class first, and each class is managed as a table of its own.

Partitions are named `<table>_p<YYYYMMDD>` for a day and `<table>_p<YYYYMM>` for a month, and the manager touches only partitions with those names. There is no DEFAULT partition, so a write outside the partitions fails. An adapter that writes to a partitioned table turns that failure into `Cbox\Cms\Contracts\Storage\PartitionMissing`, with the error code `partition_missing`.

## cms:partitions:maintain

Without options, the command creates the partitions from the span that holds now to `runway_days` ahead, 14 by default, removes the partitions whose span ended more than `retention_days` ago, and runs `ANALYZE` on the tables whose partitions it changed. It reads now from the `Clock`. It removes a partition with `DETACH PARTITION CONCURRENTLY` and `DROP TABLE`, never with `DELETE`.

- **It runs as the owner role.** It runs on `cbox-cms.database.owner_connection` and refuses the app role's connection with exit code 78.
- **It is scheduled.** The core schedules it every hour, in a process that has the owner connection. That is the maintenance process described on [cms:doctor](doctor.md#processes-web-queue-and-maintenance).
- **Runs do not overlap.** A run holds an advisory lock for its whole length.
- **It gives up politely.** Every step runs with `lock_timeout` 2 seconds and is tried again with a growing wait, three attempts by default. A table whose lock stays busy does not stop the others; the command exits 75 at the end, and the next run tries again.
- **A new partition gets its parent's settings.** It gets the parent's row security flags and the parent's grants, so the owner's default privileges never reach a partition.

`--from` and `--to` only create the partitions that cover a range of dates, for rows that arrive with past or future keys.

The exit codes: 0 done, 2 invalid options, 75 a lock was busy on every attempt, 78 not the owner's connection or a table the manager cannot manage, such as one with a DEFAULT partition.

`cms:doctor` checks the runway with `partitions.runway`: every table must have unbroken partitions from now to `cbox-cms.doctor.partition_runway_days` ahead, 7 by default. The check does not block the kernel from starting, because the scheduler of the started application extends the runway.

## Partitions in tests

A test that writes to a partitioned table first creates the partitions of the dates it writes at, with the testkit's `app(PartitionFixtures::class)->cover($from, $to)` or `coverClock($clock, $ahead)`. The harness `RealPostgres` builds the schema once per process, and partitions a test created stay until the schema is rebuilt.
