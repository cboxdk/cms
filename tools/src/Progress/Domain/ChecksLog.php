<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Progress\Domain;

/**
 * CHECKS-LOG.md, the log of changed checks (GUARDRAILS 7.3): every test, test expectation, suite,
 * tool or analysis configuration, selftest plant and CI file a task or a review commit adds,
 * changes or removes, with what changed and why. The file has one level-two heading per block,
 * such as `## M0`, and an entry is a list item under it as in ProgressLedger. A task's record names
 * the task and says GUARDRAILS 7.3, under the heading of the task's block. PROGRESS.md keeps only
 * the open decisions under "Til review af Sylvester".
 */
final readonly class ChecksLog
{
    /** The file at the root of the repository. */
    public const string FILE = 'CHECKS-LOG.md';

    /** What a record says, so a stray mention of a task is no record. */
    public const string RULE = 'GUARDRAILS 7.3';

    private function __construct(private ProgressLedger $ledger) {}

    public static function fromMarkdown(string $markdown): self
    {
        return new self(ProgressLedger::fromMarkdown($markdown));
    }

    /**
     * The entries under the heading of a block, such as M0, in order.
     *
     * @return list<string>
     */
    public function entries(string $block): array
    {
        return $this->ledger->entries($block);
    }

    /**
     * Whether an entry under the task's block names the task and says GUARDRAILS 7.3.
     */
    public function records(TaskId $task): bool
    {
        return array_any($this->entries($task->block()), static fn (string $entry): bool => $task->namedIn($entry) && self::isRecord($entry));
    }

    /**
     * Whether an entry says GUARDRAILS 7.3.
     */
    public static function isRecord(string $entry): bool
    {
        return str_contains($entry, self::RULE);
    }

    /**
     * The entries under a block's heading here that the earlier log does not have, in order: a new
     * entry, or an entry whose text changed.
     *
     * @return list<string>
     */
    public function addedSince(self $earlier, string $block): array
    {
        return $this->ledger->addedSince($earlier->ledger, $block);
    }
}
