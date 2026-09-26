<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Services\Boundary;

use Cbox\Cms\Tooling\Services\Domain\Checkout;
use Symfony\Component\Process\Process;
use UnexpectedValueException;

/**
 * Finds the checkout a directory belongs to and the main checkout of its repository, from
 * `git rev-parse --path-format=absolute --git-dir --git-common-dir --show-toplevel`.
 *
 * The main checkout is the directory that holds the common git directory, `<main>/.git`. The
 * checkout is a linked worktree when its git directory is not the common one
 * (`<main>/.git/worktrees/<name>`). A directory outside a git checkout, a bare repository and a
 * common git directory that is not named .git have no main checkout, and resolve throws.
 */
final readonly class GitCheckout
{
    public static function resolve(string $directory): Checkout
    {
        $process = new Process(
            ['git', 'rev-parse', '--path-format=absolute', '--git-dir', '--git-common-dir', '--show-toplevel'],
            $directory,
            ['GIT_OPTIONAL_LOCKS' => '0', 'GIT_DIR' => false, 'GIT_WORK_TREE' => false, 'GIT_COMMON_DIR' => false],
            null,
            60,
        );
        $process->run();
        $lines = explode("\n", trim($process->getOutput()));

        if (! $process->isSuccessful() || count($lines) !== 3) {
            $message = trim(preg_replace('/\s+/', ' ', $process->getErrorOutput()) ?? '');

            throw new UnexpectedValueException("Cannot find the git checkout of {$directory}: ".($message === '' ? 'git rev-parse exited '.($process->getExitCode() ?? 'without an exit code').'.' : $message));
        }

        [$gitDir, $commonDir, $root] = array_map(self::real(...), $lines);

        if (basename($commonDir) !== '.git') {
            throw new UnexpectedValueException("Cannot find the main checkout of {$root}: its common git directory, {$commonDir}, is not the .git directory of a checkout.");
        }

        $mainRoot = dirname($commonDir);

        if ($gitDir === $commonDir && $root !== $mainRoot) {
            throw new UnexpectedValueException("Cannot tell whether {$root} is the main checkout {$mainRoot} or a linked worktree of it: it shares the main checkout's git directory.");
        }

        return new Checkout($root, $mainRoot);
    }

    private static function real(string $path): string
    {
        $real = realpath($path);

        if ($real === false) {
            throw new UnexpectedValueException("git rev-parse named {$path}, which does not exist.");
        }

        return $real;
    }
}
