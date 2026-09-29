---
title: Developers
weight: 20
description: How the kernel's code is organised and checked, how cms:doctor, the partition manager and operations work, how the services and tests are kept apart, the configuration reference, and the strictness of Eloquent models.
---

# Developers

This section is for people who work on the kernel itself.

- [Architecture and layers](architecture.md): the package, its modules, the boundaries between them and the layer rules the architecture tests hold.
- [Gates and CI](gates-and-ci.md): `composer check`, the eleven gates, the PR profile and `bin/ci`.
- [cms:doctor](doctor.md): the checks, the processes of an installation, the exit codes and the JSON document.
- [Partitions](partitions.md): the partition manager and `cms:partitions:maintain`.
- [Operations](operations.md): long flows as operations in laravel-operations, chunk by chunk, with resume.
- [Services and isolation](services.md): the shared Docker services and how each checkout gets its own test database and Valkey prefix.
- [Configuration](configuration.md): every key of `config/cbox-cms.php`.
- [Infrastructure models](models.md): Eloquent models and the strictness the kernel sets for every model.
