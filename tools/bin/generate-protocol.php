<?php

declare(strict_types=1);

/*
 * `composer generate:protocol` (GUARDRAILS 2.2): writes the codecs of the kernel's JSON Schemas,
 * the contract versions in packages/contracts/resources/schemas that ProtocolSchemas binds to the
 * classes of the contracts, into packages/core/src/Codecs/Boundary/Generated, with
 * Cbox\Cms\Tooling\Protocol\Adapter\ProtocolGeneration.
 *
 *   php tools/bin/generate-protocol.php [--root=<dir>]
 *
 * It reads the schemas below --root, by default this repository, writes only the files whose bytes
 * differ and removes the other files in the codecs' directory, so a second run changes nothing.
 * Gate 6, `composer check:generated`, runs it and fails when the committed codecs differ.
 *
 * Exits 0 when the codecs are current or were written, with the catalog's exit code of the first
 * problem when a schema is missing (66) or invalid (65) or a file cannot be written (73), and 2 on
 * a usage error.
 */

use Cbox\Cms\Tooling\Check\Boundary\CommandLine;
use Cbox\Cms\Tooling\Protocol\Adapter\ProtocolGeneration;
use Cbox\Cms\Tooling\Protocol\Boundary\GenerateProtocolOptions;

$repository = (string) realpath(dirname(__DIR__, 2));

require $repository.'/vendor/autoload.php';

try {
    $options = GenerateProtocolOptions::parse(CommandLine::arguments());
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage()."\n".GenerateProtocolOptions::USAGE."\n");
    exit(2);
}

exit(ProtocolGeneration::main($options->root ?? $repository, STDOUT, STDERR));
