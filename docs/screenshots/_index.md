---
title: Screenshots
weight: 90
description: The terminal output the documentation shows, where each screenshot is used, and how to capture them again.
---

# Screenshots

The screenshots of the documentation are the real output of commands, drawn as a terminal window in SVG. The list of shots is `Cbox\Cms\Tooling\Docs\Domain\Screenshots`: for each, the command, the caption the pages use and the exit code the command must end with.

## Capturing them

`composer docs:screenshots` runs each command from the root of the repository, with colour on and a terminal 120 columns wide, and writes `docs/screenshots/<key>.svg`. It fails, and writes nothing for that shot, when a command ends with another exit code than its entry expects, so a picture of a broken environment is never committed. `composer docs:screenshots -- --only=<key>` captures one shot.

The output of `cms:doctor` depends on the clock, the versions, the paths and the services of the machine it runs on, so a shot of it runs in a scene: `php tools/bin/docs-scene.php <scene> <command>` boots the workbench application as `vendor/bin/testbench` does, binds a fixed clock and the core's fake probes of the doctor, and runs the command. The doctor's checks, their order and their messages are the real ones; the answers of PHP, Postgres, Valkey and the files are fixed, and the probes name their targets `fake`. The prompt line shows the command a developer types. So the shots need no services, and capturing them again on any machine and any day gives the same bytes: a test compares every committed image with what its command draws now, and fails when a change to a command's output leaves an image stale.

`composer docs:check` holds the list, the files and the pages together: every entry has its file, every file here has an entry, a page outside this folder embeds every entry, and every embed uses the entry's caption as its alt text.

## The shots

`doctor`, on [cms:doctor](../developers/doctor.md) and the [Quickstart](../quickstart.md):

![cms:doctor on a healthy installation. Every runtime check passes, and each line says what the check looked at and what it found.](doctor.svg)

`doctor-violation`, on [cms:doctor](../developers/doctor.md):

![cms:doctor with allow_url_fopen turned on. The failing check gives the cause, the fix and the error code, and the doctor exits 78.](doctor-violation.svg)
