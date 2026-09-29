---
title: Partitions
weight: 24
description: How the partition manager keeps the range partitions of the kernel's tables, on time or on a sequence, what cms:partitions:maintain does, and how a test covers the dates it writes at.
---

# Partitions

Tables that grow with time are partitioned by range, so old rows go by dropping a whole partition instead of deleting rows (PRD 4, 4.2). The partition manager in `Cbox\Cms\Core\Partitions` keeps them, and `cms:partitions:maintain` runs it.

## The tables

A table partitioned on time is listed in `cbox-cms.database.partitions.tables` with three settings:

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
| `changesets` | `uuid7` | day | kept |
| `changeset_principals` | `uuid7` | day | kept |

The receipt tables are partitioned by retention class first, and each class is managed as a table of its own. Changesets and their on-behalf-of chains are kept like revisions (PRD 4), so their partitions are never dropped.

Partitions are named `<table>_p<YYYYMMDD>` for a day and `<table>_p<YYYYMM>` for a month, and the manager touches only partitions with those names.

## Tables on a sequence

Some tables are partitioned on a `bigint` that a sequence feeds: `revision_payloads` on `revision_id` and `events` on `event_id` (PRD 4.1, 7.2). Such a table has the key `bigint` and no interval:

| Setting | Values | Meaning |
|---|---|---|
| `key` | `bigint` | The partition key is a `bigint` column that the sequence feeds. |
| `width` | a whole number of at least 1 | How many ids each partition holds. A partition holds the ids from a multiple of `width`. |
| `sequence` | a sequence name | The sequence that feeds the key, in the owner connection's search path. It must count up from 0 or more, and the doctor's role must be able to read it. |
| `retention_days` | a whole number, or `null` | How many days after its newest row a partition the sequence has passed is removed. `null` keeps every partition. |
| `retention_column` | a column name, or `null` | The `timestamptz` column the age of a row is read from. Required with `retention_days`, and `null` without it. |

Partitions are named `<table>_p<lower bound>`, with the lower bound zero-padded to 19 digits, the length of the largest `bigint`: with a width of 1000000, `events_interactive_p0000000000001000000` holds the ids from 1000000 to 1999999.

The runway is counted in empty partitions ahead of the sequence's current value, not in days, because a sequence moves with the writes. A run creates the partition that holds the current value and `runway_partitions` empty partitions after it, 2 by default. The current value is the last id the sequence handed out, read from its `last_value` and `is_called`.

Size `width` so one partition holds at least a day of the table's peak inserts. The hourly run then keeps the runway ahead, and a scheduler that stops leaves days before a write fails rather than minutes. At the scale of PRD 4, 500,000 events a day, a width of 1,000,000 ids gives two days per partition.

A partition is removed when the sequence has passed it, so it can get no new id, and the newest `retention_column` value in it is `retention_days` old. A passed partition without rows is removed as well. The manager reads the partitions in id order and stops at the first one it keeps, because ids follow time. It reads with `row_security` off, so row security that applies to the owner role makes Postgres refuse the read, and the table is reported, instead of a partition with hidden rows passing for empty. An index on the retention column keeps the read cheap.

A table with a LIST level above the range, such as `revision_payloads` by kind, is listed once per leaf parent, for example `revision_payloads_published` and `revision_payloads_draft`, each with the sequence they share. The core's `events` is partitioned by stream first (PRD 7.5), so it is listed as `events_interactive` and `events_bulk`, each with `key` `bigint`, `width` 1000000, `sequence` `events_event_id_seq`, `retention_days` 30 and `retention_column` `occurred_at` (PRD 7.10), and `events_occurred_at` is the index on the retention column. The [events](../addons/events.md) page describes the log. A run analyzes their root once. The core's `revision_payloads` is listed as `revision_payloads_draft` and `revision_payloads_published`, each with `key` `bigint`, `width` 10000000 and `sequence` `revisions_revision_id_seq`, without retention: drafts are thinned by rewriting a partition, never by dropping one (PRD 4.1). There is no DEFAULT partition, so a write outside the partitions fails. An adapter that writes to a partitioned table turns that failure into `Cbox\Cms\Contracts\Storage\PartitionMissing`, with the error code `partition_missing`.

## cms:partitions:maintain

Without options, the command creates the partitions from the span that holds now to `runway_days` ahead, 14 by default, and the runway ahead of the sequence of each table on a sequence, removes the partitions past retention, and runs `ANALYZE` on the tables whose partitions it changed. It reads now from the `Clock`. It removes a partition with `DETACH PARTITION CONCURRENTLY` and `DROP TABLE`, never with `DELETE`.

- **It runs as the owner role.** It runs on `cbox-cms.database.owner_connection` and refuses the app role's connection with exit code 78.
- **It is scheduled.** The core schedules it every hour, in a process that has the owner connection. That is the maintenance process described on [cms:doctor](doctor.md#processes-web-queue-and-maintenance).
- **Runs do not overlap.** A run holds an advisory lock for its whole length.
- **It gives up politely.** Every step runs with `lock_timeout` 2 seconds and is tried again with a growing wait, three attempts by default. A table whose lock stays busy does not stop the others; the command exits 75 at the end, and the next run tries again.
- **A new partition gets its parent's settings.** It gets the parent's row security flags and the parent's grants, so the owner's default privileges never reach a partition.

`--from` and `--to` only create the partitions that cover a range of dates, for rows that arrive with past or future keys, and the runway ahead of the sequence of each table on a sequence, because a range of dates says nothing about ids.

The exit codes: 0 done, 2 invalid options, 75 a lock was busy on every attempt, 78 not the owner's connection or a table the manager cannot manage, such as one with a DEFAULT partition.

`cms:doctor` checks the runway with `partitions.runway`: every table must have unbroken partitions from now to `cbox-cms.doctor.partition_runway_days` ahead, 7 by default, and every table on a sequence at least `cbox-cms.doctor.partition_runway_partitions` empty partitions ahead of its sequence, 1 by default. The check does not block the kernel from starting, because the scheduler of the started application extends the runway.

## Partitions in tests

A test that writes to a partitioned table first creates the partitions of the dates it writes at, with the testkit's `app(PartitionFixtures::class)->cover($from, $to)` or `coverClock($clock, $ahead)`. The harness `RealPostgres` builds the schema once per process, and partitions a test created stay until the schema is rebuilt.
