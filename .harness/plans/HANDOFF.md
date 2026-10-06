# B1 handoff (6 Oct 2026)

- main: done through X15. Not merged: wip/B1-T13 (shell, built, unverified), wip/B1-X12, wip/B1-X16 (built, unverified), wip/B1-slim-guides (CLAUDE.md/AGENTS.md 350 KB -> 24 KB; needs one green composer check, gate 5 failed only on load-sensitive tests).
- Workflow: .claude/workflows/cms-milestone.js here adds skipVerify, buildModel/integrateModel, and builders run only affected checks.
- Next: merge slim-guides after a green check, then run the workflow with planFile .harness/plans/B1-run5.json, skipVerify true, maxParallel 2, maxReviewRounds 0, order T13, T14, T15, T18 first.
