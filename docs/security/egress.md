---
title: Egress
weight: 53
description: The rule that every outbound request goes through one gateway with an SSRF guard, how the architecture tests hold it, and why PHP runs with allow_url_fopen off.
---

# Egress

A CMS fetches URLs that people and other systems give it, which makes it a way into the network it runs in. The rule is therefore that every outbound request goes through one gateway, the namespace `Cbox\Cms\Core\Egress`, which checks the destination with an SSRF guard before it connects (GUARDRAILS 3).

## What the rule covers

The architecture tests fail on every use outside the gateway namespace of:

- HTTP clients: Guzzle, Laravel's `Http` facade and the other clients, and the `curl_*` functions;
- sockets: `fsockopen`, `pfsockopen`, `stream_socket_client`, the `socket_*` and `ftp_*` functions;
- every function that opens a file name, because PHP's URL wrappers let it fetch `http://`, `https://` and `ftp://`: `file_get_contents`, `fopen`, `file`, `readfile`, `copy`, `SplFileObject`, `DOMDocument` and `XMLReader` loading, and the others;
- the framework's services that reach other hosts: the mailers, notifications, the filesystem disks and the image manager;
- the functions that run a program, and Symfony and Illuminate Process, because a program can make its own requests.

Outside the gateway, a class may use one of these only when the architecture test lists it as an exception, with the names it may use and the reason. The exceptions are the classes that read and write the local files the kernel owns, such as the registry cache, the blueprint files and the generated code, each of which refuses a path that names a stream wrapper before it opens it; the doctor's probe that runs `node --version`; and the testkit's helpers for tests.

## allow_url_fopen

The PHP of every process that runs Cbox CMS has `allow_url_fopen` off, so a file function that the tests did not catch still cannot open a URL. `cms:doctor` checks it as `php.allow_url_fopen`, and the check blocks the kernel from starting. The php container of the development environment sets it in `docker/php/conf.d/cms.ini`.

## Today

There is no gateway yet: `Cbox\Cms\Core\Egress` does not exist, and no kernel code makes an outbound request. The gateway comes with the first feature that needs one.
