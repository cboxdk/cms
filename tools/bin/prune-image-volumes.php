<?php

declare(strict_types=1);

/*
 * `composer image:prune [-- --dry-run]`: removes the volumes of the dev image (CheckoutVolume:
 * node_modules, .cache and the bootstrap cache) whose checkout is gone, as after a worktree was
 * removed by hand. Every checkout that ran a command in the dev image has them, labelled with the
 * checkout's path. It keeps every other volume, prints each volume of the dev image with its
 * verdict, and with --dry-run removes nothing. `composer check:selftest` removes its worktree's
 * volumes itself.
 *
 * Exits 0 when every stale volume was removed, 1 when docker cannot list or remove one, and 2 on
 * a usage error.
 */

use Cbox\Cms\Tooling\Check\Boundary\CommandLine;
use Cbox\Cms\Tooling\DevImage\Domain\CheckoutVolume;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$arguments = CommandLine::arguments();

if ($arguments !== [] && $arguments !== ['--dry-run']) {
    fwrite(STDERR, "Usage: composer image:prune [-- --dry-run]\n");
    exit(2);
}

$dryRun = $arguments === ['--dry-run'];
$list = new Process(['docker', 'volume', 'ls', '--filter', 'label='.CheckoutVolume::LABEL, '--format', '{{.Name}}'."\t".'{{.Label "'.CheckoutVolume::LABEL.'"}}'], null, null, null, 120);
$list->run();

if (! $list->isSuccessful()) {
    fwrite(STDERR, 'Cannot list the volumes of the dev image: '.trim($list->getErrorOutput())."\n");
    exit(1);
}

$failed = false;

foreach (array_filter(explode("\n", trim($list->getOutput()))) as $line) {
    [$volume, $checkout] = array_pad(explode("\t", $line, 2), 2, '');
    $reason = CheckoutVolume::staleReason($volume, $checkout, is_dir($checkout) ? realpath($checkout) : false);

    if ($reason === null) {
        fwrite(STDOUT, "keep    {$volume}  {$checkout}\n");

        continue;
    }

    if ($dryRun) {
        fwrite(STDOUT, "stale   {$volume}  {$reason}\n");

        continue;
    }

    $remove = new Process(['docker', 'volume', 'rm', $volume], null, null, null, 120);
    $remove->run();
    $failed = $failed || ! $remove->isSuccessful();
    fwrite(STDOUT, ($remove->isSuccessful() ? 'removed ' : 'FAILED  ')."{$volume}  {$reason}".($remove->isSuccessful() ? '' : ': '.trim($remove->getErrorOutput()))."\n");
}

exit($failed ? 1 : 0);
