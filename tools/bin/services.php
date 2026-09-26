<?php

declare(strict_types=1);

/*
 * `composer services:up` and `composer services:down`: the development and test services of the
 * main checkout's compose.yaml, safe to run from a linked worktree (PROGRESS.md, "Beslutninger
 * fra Sylvester", parallel worktrees).
 *
 *   php tools/bin/services.php up|down
 *
 * It resolves the checkout of the working directory, where Composer runs its scripts, and the
 * main checkout of its repository from git, and always runs docker compose with the main
 * checkout's compose.yaml and the main checkout as the project directory, so no container ever
 * mounts a worktree. From the main checkout, up runs `up -d --wait` and then the idempotent init
 * script, and down stops the services and keeps the volumes. From a linked worktree, up starts
 * only Postgres and Valkey with `--no-recreate`, runs the init script and says that the php
 * container mounts the main checkout; down refuses, because the services are shared. The plan is
 * Cbox\Cms\Tooling\Services\Domain\ServicesPlan.
 *
 * Exits 0 when every command passed, with the exit code of the first docker command that failed,
 * 1 when it refuses or cannot resolve the checkout, and 2 on a usage error.
 */

use Cbox\Cms\Tooling\Check\Boundary\CommandLine;
use Cbox\Cms\Tooling\Services\Boundary\CurrentHostUser;
use Cbox\Cms\Tooling\Services\Boundary\GitCheckout;
use Cbox\Cms\Tooling\Services\Domain\ServicesAction;
use Cbox\Cms\Tooling\Services\Domain\ServicesPlan;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$arguments = CommandLine::arguments();
$action = count($arguments) === 1 ? ServicesAction::tryFrom($arguments[0]) : null;

if ($action === null) {
    fwrite(STDERR, "Usage: php tools/bin/services.php up|down\n");
    exit(2);
}

try {
    $plan = ServicesPlan::for($action, GitCheckout::resolve((string) getcwd()), CurrentHostUser::read());
} catch (UnexpectedValueException $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}

if ($plan->refused()) {
    fwrite(STDERR, $plan->refusal."\n");
    exit(1);
}

foreach ($plan->commands as $command) {
    fwrite(STDOUT, '> '.implode(' ', array_map(static fn (string $argument): string => preg_match('/^[\w.\/:=@%+-]+$/', $argument) === 1 ? $argument : escapeshellarg($argument), $command))."\n");

    $process = new Process($command, null, $plan->environment, null, null);
    $exitCode = $process->run(static function (string $type, string $buffer): void {
        fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
    });

    if ($exitCode !== 0) {
        exit($exitCode);
    }
}

foreach ($plan->notes as $note) {
    fwrite(STDOUT, $note."\n");
}

exit(0);
