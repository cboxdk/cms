<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Adapter;

use Symfony\Component\Process\Process;

/**
 * Keeps a mutation step from leaving files in the checkout for the steps after it.
 *
 * A mutation of a class that writes files can drop the directory from a path, so the process
 * that tests it writes the file into its working directory, the checkout's root: a mutation of
 * FileRegistryCache wrote actions.php there, and the next step's first run of its suites failed
 * on it (QualityGatesTest holds every PHP file in the root to the analysis), so its mutations were
 * never checked (M1-T66). The Pest process that runs a step's mutations lists the files git does
 * not track and does not ignore when it starts, and when it ends removes each such file that was
 * not there at the start, and says so on standard error. It is started only by the mutation steps
 * (MutationSteps sets PestMutationReport::VARIABLE), whose runs are a checkout of their own in CI
 * (one per job) and in bin/ci's containerized run.
 */
final readonly class CheckoutGuard
{
    /**
     * @param  list<string>  $before  the untracked files when the run started, relative to $root
     */
    private function __construct(
        private string $root,
        private array $before,
    ) {}

    /**
     * The guard of the checkout at $root, or null when $root is not a git checkout.
     */
    public static function start(string $root): ?self
    {
        $before = self::untracked($root);

        return $before === null ? null : new self($root, $before);
    }

    /**
     * Removes the untracked files that appeared since the start, and gives them, sorted.
     *
     * @return list<string>
     */
    public function removeStrays(): array
    {
        $after = self::untracked($this->root);

        if ($after === null) {
            return [];
        }

        $strays = self::strays($this->before, $after);

        foreach ($strays as $stray) {
            $path = $this->root.'/'.$stray;

            if (is_file($path) || is_link($path)) {
                unlink($path);
            }
        }

        return $strays;
    }

    /**
     * The files of $after that are not in $before, sorted.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @return list<string>
     */
    public static function strays(array $before, array $after): array
    {
        $strays = array_values(array_diff($after, $before));
        sort($strays);

        return $strays;
    }

    /**
     * The files of the checkout at $root that git neither tracks nor ignores, or null when git
     * cannot list them.
     *
     * @return list<string>|null
     */
    private static function untracked(string $root): ?array
    {
        $process = new Process(['git', 'ls-files', '--others', '--exclude-standard', '-z'], $root, ['GIT_OPTIONAL_LOCKS' => '0'], null, 60);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        return array_values(array_filter(explode("\0", $process->getOutput()), static fn (string $file): bool => $file !== ''));
    }
}
