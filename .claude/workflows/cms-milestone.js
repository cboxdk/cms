export const meta = {
  name: 'cms-milestone',
  description: 'Build one Cbox CMS block from MILESTONES.md: plan, build independent tasks in parallel worktrees, integrate through a merge queue that runs every gate, adversarial review, exit criteria, PROGRESS.md',
  whenToUse: 'Autopilot development of laravel-cms. Pass args {block: "M1"} (ids in PROGRESS.md). Optional: maxParallel (default 3; 1 builds one task at a time).',
  phases: [
    { title: 'Plan', detail: 'break the block into small tasks with a dependency graph, acceptance checks and exit criteria' },
    { title: 'Build', detail: 'independent tasks in parallel, each in its own worktree with its own test database; verify and fix there' },
    { title: 'Integrate', detail: 'one task at a time: rebase on main, run every gate on the result, fast-forward main' },
    { title: 'Review', detail: 'block-wide regression gate, lens reviewers, two skeptics per finding, fixes' },
    { title: 'Exit', detail: 'run the exit criteria and record the result in PROGRESS.md' },
  ],
}

const BLOCK = args && args.block
if (!BLOCK) {
  throw new Error('cms-milestone needs args {block: "<id>"}, e.g. {block: "M1"}')
}
const MAX_PARALLEL = Math.max(1, (args && args.maxParallel) || 3)
const MAX_FIX_ROUNDS = (args && args.maxFixRounds) || 3
const MAX_INTEGRATION_ROUNDS = (args && args.maxIntegrationRounds) || 2
const MAX_REVIEW_ROUNDS = (args && args.maxReviewRounds) || 2

const REPO = '/Users/sylvester/Projects/laravel-cms'
const WT_ROOT = '/Users/sylvester/Projects/laravel-cms-worktrees'
const PLAN_REPO = '/Users/sylvester/Projects/cbox-cms'
const CONTEXT = `The main checkout is ${REPO} (git repo, local only, never push, never add a remote). Planning documents: ${PLAN_REPO}/PRD.md (Danish, architecture), ${PLAN_REPO}/GUARDRAILS.md (coding rules), ${PLAN_REPO}/MILESTONES.md (build order and exit criteria). Working state: ${REPO}/PROGRESS.md; read "Beslutninger fra Sylvester" there. The current block is ${BLOCK} as listed in PROGRESS.md and described in MILESTONES.md. Never change the cboxdk ecosystem repos.`

const wtOf = id => `${WT_ROOT}/${BLOCK}-${id}`
const branchOf = id => `wip/${BLOCK}-${id}`

const WORKTREE_RULES = `Worktree rules:
- Work only in your worktree. Never edit files in the main checkout ${REPO}, and never commit on main there.
- The testkit gives every checkout its own Postgres test database and Valkey prefix, so the Postgres and Valkey suites and "composer check" can run next to other worktrees. The shared services run from the main checkout; if they are down, run "composer services:up" in ${REPO}, never "docker compose up" in a worktree.
- Do not run "composer check:selftest" or the CI container run (compose.ci.yaml); the integration step runs those one at a time.
- Do not edit PROGRESS.md: the merge queue writes your task's entries into it from your result before main moves. Report every existing check you changed or removed (a test or its expectation, a suite, tool or analysis configuration, a selftest plant, a CI file: GUARDRAILS 7.3) in changedChecks, with what changed and why, and interpretations, items for human review and blockers in their fields.
- Never make a commit that changes no file. A task that changes nothing in this repository, such as one that edits only the planning repo, makes no commit here and says so in its result.`

const TASK = {
  type: 'object',
  properties: {
    id: { type: 'string' },
    title: { type: 'string' },
    goal: { type: 'string' },
    prdRefs: { type: 'array', items: { type: 'string' } },
    acceptance: { type: 'array', items: { type: 'string' } },
    dependsOn: { type: 'array', items: { type: 'string' } },
    touches: { type: 'array', items: { type: 'string' }, description: 'paths or areas the task expects to change' },
  },
  required: ['id', 'title', 'goal', 'acceptance', 'dependsOn'],
}
const PLAN = {
  type: 'object',
  properties: {
    block: { type: 'string' },
    summary: { type: 'string' },
    tasks: { type: 'array', items: TASK },
    exitCriteria: {
      type: 'array',
      items: {
        type: 'object',
        properties: { id: { type: 'string' }, description: { type: 'string' }, command: { type: 'string' } },
        required: ['id', 'description'],
      },
    },
    blockers: { type: 'array', items: { type: 'string' } },
  },
  required: ['block', 'summary', 'tasks', 'exitCriteria'],
}
const CRITIQUE = {
  type: 'object',
  properties: {
    missing: { type: 'array', items: { type: 'string' } },
    problems: { type: 'array', items: { type: 'string' } },
  },
  required: ['missing', 'problems'],
}
const TASK_RESULT = {
  type: 'object',
  properties: {
    status: { type: 'string', enum: ['done', 'failed', 'blocked'] },
    commit: { type: 'string' },
    summary: { type: 'string' },
    checksRun: { type: 'array', items: { type: 'string' } },
    blocker: { type: 'string' },
    interpretations: { type: 'array', items: { type: 'string' } },
    forHumanReview: { type: 'array', items: { type: 'string' } },
    changedChecks: { type: 'array', items: { type: 'string' }, description: 'every existing check the task changed or removed (a test or its expectation, a suite, tool or analysis configuration, a selftest plant, a CI file; GUARDRAILS 7.3), each with what changed and why; empty when none' },
  },
  required: ['status', 'summary'],
}
const VERIFY = {
  type: 'object',
  properties: {
    pass: { type: 'boolean' },
    failures: { type: 'array', items: { type: 'string' } },
    checksRun: { type: 'array', items: { type: 'string' } },
    head: { type: 'string', description: 'full sha of the commit the checks ran on (git rev-parse HEAD)' },
  },
  required: ['pass', 'failures', 'checksRun'],
}
const INTEGRATION = {
  type: 'object',
  properties: {
    merged: { type: 'boolean' },
    mainHead: { type: 'string' },
    conflicts: { type: 'array', items: { type: 'string' } },
    failures: { type: 'array', items: { type: 'string' } },
    checksRun: { type: 'array', items: { type: 'string' } },
    wallTimeSeconds: { type: 'integer' },
    progressRecorded: { type: 'boolean', description: 'true only when composer progress:check passed for every task before main moved' },
    progressCheck: { type: 'string', description: 'the last output of composer progress:check' },
  },
  required: ['merged', 'failures', 'checksRun', 'progressRecorded'],
}
const FINDINGS = {
  type: 'object',
  properties: {
    findings: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          title: { type: 'string' },
          severity: { type: 'string', enum: ['blocker', 'high', 'medium', 'low'] },
          file: { type: 'string' },
          line: { type: 'integer' },
          problem: { type: 'string' },
          fix: { type: 'string' },
        },
        required: ['title', 'severity', 'file', 'problem', 'fix'],
      },
    },
  },
  required: ['findings'],
}
const VERDICT = {
  type: 'object',
  properties: { refuted: { type: 'boolean' }, reason: { type: 'string' } },
  required: ['refuted', 'reason'],
}
const EXIT = {
  type: 'object',
  properties: {
    criteria: {
      type: 'array',
      items: {
        type: 'object',
        properties: { id: { type: 'string' }, pass: { type: 'boolean' }, evidence: { type: 'string' } },
        required: ['id', 'pass', 'evidence'],
      },
    },
    allPass: { type: 'boolean' },
  },
  required: ['criteria', 'allPass'],
}
const PROGRESS_RESULT = {
  type: 'object',
  properties: {
    blockStatus: { type: 'string', enum: ['done', 'incomplete', 'blocked'] },
    nextBlock: { type: 'string' },
    commit: { type: 'string' },
  },
  required: ['blockStatus'],
}

const VERIFY_RULES = `Run the real checks in the worktree, do not assume: "composer check" and the task's acceptance items. Report pass only if every gate is green and every acceptance item is met, and report the full sha of HEAD you checked in head (the working tree must be clean). Do not change any files.`

// ---------------------------------------------------------------- Plan
phase('Plan')

// args.planFile reuses a plan from an earlier run of the same block (a JSON file with the PLAN shape) and skips planning.
const PLAN_FILE = args && args.planFile
let plan = PLAN_FILE ? await agent(
  `Read the JSON file ${PLAN_FILE} and return its content as the plan, exactly as it is: same tasks, ids, dependsOn, acceptance items and exit criteria. Do not change any files.`,
  { schema: PLAN, label: `load plan ${BLOCK}`, effort: 'low' },
) : await agent(
  `${CONTEXT}

Plan block ${BLOCK}. Read PROGRESS.md, the block in MILESTONES.md, the PRD sections it depends on, GUARDRAILS.md and the current repo state (git log, files). Work that is already committed and green counts as done; plan only what remains.

Break the remaining work into small tasks that one agent can finish, test and commit in one go. The tasks are built in parallel by up to ${MAX_PARALLEL} agents in separate worktrees, so dependsOn must be complete and exact: a task lists every task whose code or files it needs. Tasks without a dependency between them must be safe to build at the same time; when two tasks would change the same central file heavily (a schema, a shared config, the same class), make one depend on the other. Fill touches with the paths or areas each task changes. Every task needs concrete acceptance items that can be checked by running something (a test, a command, a query), not opinions.

Copy the block's exit criteria from MILESTONES.md into exitCriteria, each with a command or procedure that proves it.

Put decisions reserved for Sylvester (see CLAUDE.md) in blockers, and plan around them where possible. Do not change any files.`,
  { schema: PLAN, label: `plan ${BLOCK}`, effort: 'high' },
)

const critique = PLAN_FILE ? null : await agent(
  `${CONTEXT}

Here is a plan for block ${BLOCK}:
${JSON.stringify(plan, null, 2)}

Act as a completeness critic. Compare it with the block in MILESTONES.md, the PRD sections it builds, and GUARDRAILS.md (actions, DTOs, contracts with fakes and shared contract tests, real Postgres tests, observability, no placeholders, definition of done). List what is missing (work, tests, exit criteria) and what is wrong (wrong or missing dependencies for parallel building, tasks too big to finish in one go, acceptance items that cannot be checked, scope creep outside the block). Do not change any files.`,
  { schema: CRITIQUE, label: `critique ${BLOCK}` },
)

if (critique && (critique.missing.length || critique.problems.length)) {
  log(`plan critique: ${critique.missing.length} missing, ${critique.problems.length} problems; revising`)
  const revised = await agent(
    `${CONTEXT}

Revise this plan for block ${BLOCK}:
${JSON.stringify(plan, null, 2)}

A critic found:
Missing: ${JSON.stringify(critique.missing)}
Problems: ${JSON.stringify(critique.problems)}

Return the full revised plan. Keep task ids stable where the task is unchanged. Do not change any files.`,
    { schema: PLAN, label: `plan ${BLOCK} v2`, effort: 'high' },
  )
  if (revised) plan = revised
}

log(`${BLOCK}: ${plan.tasks.length} tasks, ${plan.exitCriteria.length} exit criteria, ${(plan.blockers || []).length} blockers, up to ${MAX_PARALLEL} in parallel`)

// ---------------------------------------------------------------- Build and integrate
const taskIds = new Set(plan.tasks.map(t => t.id))
const depsOf = t => (t.dependsOn || []).filter(d => taskIds.has(d))
const state = {}
plan.tasks.forEach(t => { state[t.id] = 'pending' })
const taskResults = {}

// The merge queue: one integration at a time. Tasks that finish while an integration runs wait,
// and the next integration takes them together as a batch: rebased onto main one after another and
// checked once, with every gate, on the combined result. A red batch falls back to one task at a
// time, which finds the task that breaks it. A task whose rebase is a no-op keeps the verification
// it already has on that exact commit.
const MAX_BATCH = Math.max(1, (args && args.maxBatch) || MAX_PARALLEL)
const waiting = []
let integrating = false
function integrateSerially(task, verifiedHead, report) {
  return new Promise(resolve => {
    waiting.push({ task, verifiedHead, report, resolve })
    pump()
  })
}
async function pump() {
  if (integrating || !waiting.length) return
  integrating = true
  const batch = waiting.splice(0, MAX_BATCH)
  let results = []
  try {
    if (batch.length === 1) {
      results = [await integrate(batch[0].task, batch[0].verifiedHead, batch[0].report)]
    } else {
      const r = await integrateBatch(batch.map(b => b.task), batch.map(b => Object.assign({ id: b.task.id }, b.report)))
      if (r && r.merged) {
        results = batch.map(() => r)
      } else {
        log(`batch ${batch.map(b => b.task.id).join('+')} failed; integrating one at a time`)
        for (const b of batch) results.push(await integrate(b.task, null, b.report))
      }
    }
  } catch (e) {
    while (results.length < batch.length) results.push({ merged: false, failures: ['merge queue crashed: ' + String(e)], checksRun: [] })
  }
  batch.forEach((b, i) => b.resolve(results[i] || { merged: false, failures: ['no result'], checksRun: [] }))
  integrating = false
  pump()
}

async function integrateBatch(tasks, reports) {
  const ids = tasks.map(t => t.id).join('+')
  const intBranch = `int/${BLOCK}-${tasks.map(t => t.id).join('-')}`
  const intWt = `${WT_ROOT}/${BLOCK}-int-${tasks.map(t => t.id).join('-')}`
  const list = tasks.map(t => `- ${t.id} "${t.title}": worktree ${wtOf(t.id)}, branch ${branchOf(t.id)}`).join('\n')
  return agent(
    `${CONTEXT}

Integrate these verified tasks of block ${BLOCK} into main together, as one batch. You are the only integrator running; nothing else merges while you work.
${list}

1. Create an integration worktree from the current main: "git -C ${REPO} worktree add ${intWt} -b ${intBranch} main".
2. For each task in the order listed: in its worktree, rebase its branch onto the tip of ${intBranch} ("git rebase ${intBranch}"), then fast-forward ${intBranch} to it ("git -C ${intWt} merge --ff-only <task branch>"). Resolve conflicts so both sides' intent survives. If a conflict cannot be resolved faithfully, abort, and report the batch as not merged.
3. In ${intWt}: "composer install" and "npm ci", then run every gate on the combined result: "composer check". Also run "composer check:selftest" if any task in the batch or main since the tasks started changed a gate, the tool configuration or the check itself, and the containerized CI run (docker compose -f compose.ci.yaml run --rm ci, then down) if any changed bin/ci, the CI files or the environment the gates need. Record the CI wall time.
4. In ${intWt}, record each task in PROGRESS.md, as the tasks could not, in one commit per task on ${intBranch} with message "${BLOCK}-<id>: progress" (in Danish, as the file is): under "Til review af Sylvester", one entry that starts "${BLOCK}-<id>:" and says "GUARDRAILS 7.3", naming every check the task's commits add, change or remove, with what changed and why, followed by its items for human review; under "Tolkninger", its interpretations, each starting "${BLOCK}-<id>:"; and under "Kontroller kørt", one entry "<today>, ${BLOCK}-<id>: ..." with the gates run on the batch in step 3 and their results, and for "composer check:selftest" and the containerized CI run either the result, with the CI wall time against the 15-minute budget, or why it did not run. The tasks reported: ${JSON.stringify(reports)}.
   Then run "composer progress:check -- ${BLOCK}-<id> --range=main..HEAD" in ${intWt} for each task, with --changed-checks after the task id when the task reported changed checks or its commits add, change or remove a check. It fails when an entry is missing or a commit of the batch changes no file: add the entry, or drop the empty commit with a rebase, and run it again. Report the last outputs in progressCheck, and progressRecorded true only when every one passed.
5. Only if everything is green and every "composer progress:check" passed: fast-forward main ("git -C ${REPO} merge --ff-only ${intBranch}"). Each task keeps its own commits. If git refuses because the main checkout has local changes that the merge would overwrite, do not stash or discard them; report it as a failure.
6. After a successful merge, remove every task worktree and branch of the batch. Whatever the outcome, remove ${intWt} and delete ${intBranch}. Then run "composer test-db:prune" in ${REPO} and report what it dropped.
Never push. Change PROGRESS.md only as step 4 says.`,
    { schema: INTEGRATION, label: `integrate batch ${ids}`, phase: 'Integrate', effort: 'medium' },
  )
}

async function integrate(task, verifiedHead, report = {}) {
  let lastFailures = []
  for (let round = 0; round <= MAX_INTEGRATION_ROUNDS; round++) {
    if (round > 0) {
      const fixed = await agent(
        `${CONTEXT}

Task ${task.id} "${task.title}" of block ${BLOCK} failed integration in worktree ${wtOf(task.id)} (branch ${branchOf(task.id)}).
Failures: ${JSON.stringify(lastFailures)}

This usually means the task and work merged to main since it started interact. Find the cause and fix it in the worktree without undoing the other work, and without weakening any check (GUARDRAILS 7.3). Add a regression test when it is a bug. Commit with message "${BLOCK}-${task.id}: fix integration <what>". Never push.

${WORKTREE_RULES}`,
        { schema: TASK_RESULT, label: `fix integration ${task.id} #${round}`, phase: 'Integrate' },
      )
      if (fixed) {
        for (const k of ['changedChecks', 'interpretations', 'forHumanReview', 'checksRun']) report[k] = (report[k] || []).concat(fixed[k] || [])
      }
    }
    const r = await agent(
      `${CONTEXT}

Integrate task ${task.id} "${task.title}" of block ${BLOCK} into main. You are the only integrator running; nothing else merges while you work.

1. In the worktree ${wtOf(task.id)} on branch ${branchOf(task.id)}: rebase onto the current main of ${REPO} ("git rebase main"). Resolve conflicts so both sides' intent survives; if a conflict cannot be resolved faithfully, abort the rebase and report it as a failure.
2. In the worktree, reinstall ("composer install", "npm ci") if composer.json, composer.lock, package.json or package-lock.json changed, then run every gate on the rebased result: "composer check".${verifiedHead ? ` If the rebase left HEAD at ${verifiedHead}, main has not moved since verification and "composer check" already passed on this exact commit; then skip it and apply only the selftest and CI rules below.` : ''} Also run "composer check:selftest" if the task or main since the task started changed a gate, the tool configuration or the check itself, and the containerized CI run (docker compose -f compose.ci.yaml run --rm ci, then down) if bin/ci, the CI files or the environment the gates need changed. Record the CI wall time.
3. Record the task in PROGRESS.md in the worktree, as the task could not, and commit it on the branch with message "${BLOCK}-${task.id}: progress" (in Danish, as the file is):
   - under "Til review af Sylvester", one entry that starts "${BLOCK}-${task.id}:" and says "GUARDRAILS 7.3", naming every check the task's commits add, change or remove (tests and their expectations, suites, phpunit.xml, the tool and analysis configuration, selftest plants, CI files; read "git diff main..HEAD"), with what changed and why, followed by the task's items for human review;
   - under "Tolkninger", the task's interpretations, each starting "${BLOCK}-${task.id}:";
   - under "Kontroller kørt", one entry "<today>, ${BLOCK}-${task.id}: ..." with the gates you ran in step 2 and their results, and for "composer check:selftest" and the containerized CI run either the result, with the CI wall time against the 15-minute budget, or why it did not run.
   The task reported: changed checks ${JSON.stringify(report.changedChecks || [])}; interpretations ${JSON.stringify(report.interpretations || [])}; for human review ${JSON.stringify(report.forHumanReview || [])}; checks run ${JSON.stringify(report.checksRun || [])}.
   Then run "composer progress:check -- ${BLOCK}-${task.id} --range=main..HEAD" in the worktree, with --changed-checks after the task id when the task reported changed checks or its commits add, change or remove a check. It fails when an entry is missing or a commit of the task changes no file: add the entry, or drop the empty commit with a rebase, and run it again. Report its last output in progressCheck, and progressRecorded true only when it passed.
4. Only if everything is green and "composer progress:check" passed: fast-forward main ("git -C ${REPO} merge --ff-only ${branchOf(task.id)}"). If git refuses because the main checkout has local changes that the merge would overwrite, do not stash or discard them; report it as a failure.
5. After a successful merge, remove the worktree and delete the branch, then run "composer test-db:prune" in ${REPO}, which drops the removed worktree's test database; report what it dropped.
Never push. Change PROGRESS.md only as step 3 says.`,
      { schema: INTEGRATION, label: `integrate ${task.id}${round ? ' #' + round : ''}`, phase: 'Integrate', effort: 'medium' },
    )
    if (r && r.merged) {
      if (!r.progressRecorded) log(`${task.id}: main moved without the task's PROGRESS.md entries`)
      return r
    }
    lastFailures = r ? r.failures.concat(r.conflicts || []) : ['integrator died']
  }
  return { merged: false, failures: lastFailures, checksRun: [] }
}

async function buildTask(task, phaseName) {
  const PH = phaseName || 'Build'
  const earlier = Object.values(taskResults).map(r => ({ id: r.id, status: r.status, summary: r.summary }))
  const result = await agent(
    `${CONTEXT}

Implement task ${task.id} "${task.title}" of block ${BLOCK}.
Goal: ${task.goal}
PRD references: ${JSON.stringify(task.prdRefs || [])}
Acceptance: ${JSON.stringify(task.acceptance)}
Tasks of this block finished so far: ${JSON.stringify(earlier)}

First create your worktree from the current main: "git -C ${REPO} worktree add ${wtOf(task.id)} -b ${branchOf(task.id)} main" (if it already exists from an earlier attempt, reuse it and rebase it onto main), then "composer install" and "npm ci" in it.

Write the code and its tests following GUARDRAILS.md. Run "composer check" and the acceptance items in the worktree and fix what fails. When green, commit on your branch with message "${BLOCK}-${task.id}: <what>". Do not merge into main; the merge queue does that. Never push. Never weaken a check (GUARDRAILS 7.3); if you think a check is wrong, leave it and report it in forHumanReview. If the task needs a decision reserved for Sylvester, stop and return status blocked with the blocker. Report PRD interpretations you made in interpretations.

${WORKTREE_RULES}`,
    { schema: TASK_RESULT, label: `build ${task.id}`, phase: PH },
  )
  if (!result || result.status === 'blocked') {
    return { id: task.id, status: 'blocked', summary: result ? result.summary : 'agent died', blocker: result && result.blocker }
  }

  const verify = label => agent(
    `${CONTEXT}

Verify task ${task.id} "${task.title}" of block ${BLOCK} in its worktree ${wtOf(task.id)} (branch ${branchOf(task.id)}).
Goal: ${task.goal}
Acceptance: ${JSON.stringify(task.acceptance)}

${VERIFY_RULES}

${WORKTREE_RULES}`,
    { schema: VERIFY, label, phase: PH, effort: 'medium' },
  )

  let verdict = await verify(`verify ${task.id}`)
  let round = 0
  while (verdict && !verdict.pass && round < MAX_FIX_ROUNDS) {
    round++
    const fix = await agent(
      `${CONTEXT}

Task ${task.id} "${task.title}" of block ${BLOCK} failed verification in worktree ${wtOf(task.id)}.
Failures: ${JSON.stringify(verdict.failures)}
Acceptance: ${JSON.stringify(task.acceptance)}

Fix the cause, not the check (GUARDRAILS 7.3). Add a regression test when the failure is a bug. Run the checks, then commit on the branch with message "${BLOCK}-${task.id}: fix <what>". Never push.

${WORKTREE_RULES}`,
      { schema: TASK_RESULT, label: `fix ${task.id} #${round}`, phase: PH },
    )
    if (fix) {
      result.interpretations = (result.interpretations || []).concat(fix.interpretations || [])
      result.forHumanReview = (result.forHumanReview || []).concat(fix.forHumanReview || [])
      result.changedChecks = (result.changedChecks || []).concat(fix.changedChecks || [])
    }
    verdict = await verify(`verify ${task.id} #${round}`)
  }
  if (!verdict || !verdict.pass) {
    return {
      id: task.id, status: 'failed', summary: result.summary,
      failures: verdict ? verdict.failures : ['verifier died'],
      interpretations: result.interpretations || [], forHumanReview: result.forHumanReview || [],
    }
  }

  const merged = await integrateSerially(task, verdict.head || null, result)
  return {
    id: task.id,
    status: merged.merged && merged.progressRecorded ? 'done' : 'failed',
    summary: result.summary,
    failures: merged.merged && merged.progressRecorded
      ? []
      : [merged.merged ? 'merged without its PROGRESS.md entries; composer progress:check did not pass: ' + (merged.progressCheck || 'no output') : 'integration: ' + JSON.stringify(merged.failures)],
    integration: { mainHead: merged.mainHead, checksRun: merged.checksRun, wallTimeSeconds: merged.wallTimeSeconds },
    interpretations: result.interpretations || [],
    forHumanReview: result.forHumanReview || [],
  }
}

// Builds tasks in parallel along their dependency graph. Each task is built, verified and fixed in its
// own worktree and then goes through the merge queue.
async function runTasks(tasks, phaseName) {
  const ids = new Set(tasks.map(t => t.id))
  const depsIn = t => (t.dependsOn || []).filter(d => ids.has(d))
  tasks.forEach(t => { state[t.id] = 'pending' })
  const running = new Map()
  while (true) {
    // A task whose dependency failed or was blocked cannot be built.
    let changed = true
    while (changed) {
      changed = false
      for (const t of tasks) {
        if (state[t.id] !== 'pending') continue
        const bad = depsIn(t).find(d => state[d] === 'failed' || state[d] === 'blocked')
        if (bad) {
          state[t.id] = 'blocked'
          taskResults[t.id] = { id: t.id, status: 'blocked', summary: `depends on ${state[bad]} ${bad}` }
          log(`${t.id} skipped: depends on ${state[bad]} ${bad}`)
          changed = true
        }
      }
    }
    const ready = tasks.filter(t => state[t.id] === 'pending' && depsIn(t).every(d => state[d] === 'done'))
    while (running.size < MAX_PARALLEL && ready.length) {
      const t = ready.shift()
      state[t.id] = 'running'
      running.set(t.id, buildTask(t, phaseName).catch(e => ({ id: t.id, status: 'failed', summary: 'crashed', failures: [String(e)] })).then(r => {
        state[t.id] = r.status
        taskResults[t.id] = r
        running.delete(t.id)
        log(`${t.id}: ${r.status}`)
      }))
    }
    if (!running.size) break
    await Promise.race(running.values())
  }
  for (const t of tasks) {
    if (state[t.id] === 'pending') {
      state[t.id] = 'blocked'
      taskResults[t.id] = { id: t.id, status: 'blocked', summary: 'dependency cycle or unknown dependency' }
      log(`${t.id} never became ready: dependency cycle`)
    }
  }
  return tasks.map(t => taskResults[t.id])
}

phase('Build')
await runTasks(plan.tasks, 'Build')
const results = plan.tasks.map(t => taskResults[t.id])
const blockedTasks = results.filter(r => r.status === 'blocked').map(r => r.id)
const failedTasks = results.filter(r => r.status === 'failed')

// ---------------------------------------------------------------- Review
phase('Review')

const gate = await agent(
  `${CONTEXT}

Block-wide regression gate for ${BLOCK} on main in ${REPO}, after parallel tasks were merged one by one. The merge queue fast-forwarded main without installing it, so first run "composer install" and "npm ci" in ${REPO}; they change only vendor/ and node_modules/, which git ignores. Then run "composer check", "composer check:selftest" and the containerized CI run (docker compose -f compose.ci.yaml run --rm ci, then down). Also check that no worktree of this block is left in ${WT_ROOT} for a task that was merged, and that "git worktree list" and the branches wip/${BLOCK}-* match the tasks that were not merged. Report failures precisely. Do not change any tracked files.`,
  { schema: VERIFY, label: `regression gate ${BLOCK}`, phase: 'Review', effort: 'medium' },
)
if (gate && !gate.pass) {
  log(`regression gate failed: ${gate.failures.length} failures; fixing on main`)
  await agent(
    `${CONTEXT}

The block-wide regression gate for ${BLOCK} failed on main after the parallel tasks were merged:
${JSON.stringify(gate.failures)}

Find which merged tasks interact to cause it (git log, bisect if needed), fix the cause on main in ${REPO} with a regression test, never weakening a check (GUARDRAILS 7.3). Run "composer check" and whatever else failed, then commit with message "${BLOCK}-review: fix regression <what>". Never push.`,
    { schema: TASK_RESULT, label: `fix regression ${BLOCK}`, phase: 'Review' },
  )
}

const LENSES = [
  { key: 'prd', prompt: 'Does the code do what the PRD sections for this block say? Look for missing behaviour, wrong semantics and invariants from PRD 6.5 that are not enforced or not tested.' },
  { key: 'guardrails', prompt: 'Does the code follow GUARDRAILS.md: actions and plans, envelope, DTOs and value objects, no mixed or untyped arrays outside Boundary and Adapter, contracts with fakes that pass the shared contract tests, layers, readonly per category, codecs, the kernel knowing no content types, no placeholders?' },
  { key: 'correctness', prompt: 'Look for correctness bugs: concurrency and transactions (separate connections, expected versions, idempotency), RLS as the app role, outbox atomicity, time handling, edge cases. Prefer findings you can demonstrate with a failing test.' },
  { key: 'security', prompt: 'Look for security problems: SSRF (all outbound HTTP through laravel-ssrf), secrets in logs or events, authorization outside the pipelines, classification leaks, SQL injection, unsafe defaults.' },
  { key: 'integrity', prompt: 'Check the integrity of the checks themselves in this block\'s commits (git log and diffs): weakened analysis config, excluded paths, skipped or loosened tests, changed snapshots or expectations without reason, @phpstan-ignore outside Boundary and Adapter, generated files edited by hand. Pay special attention to integration fixes and conflict resolutions from the merge queue: did one task silently undo or weaken another task\'s work or test?' },
]

const seen = new Set()
const fixed = []
const keyOf = f => `${f.file}|${f.title}`.toLowerCase()

for (let reviewRound = 1; reviewRound <= MAX_REVIEW_ROUNDS; reviewRound++) {
  const found = (await parallel(LENSES.map(lens => () =>
    agent(
      `${CONTEXT}

Review the work of block ${BLOCK} on main (see the commits whose messages start with "${BLOCK}-") through one lens.
${lens.prompt}

Report only real problems with file and line and a concrete fix. No style nits, no praise. Do not change any files.`,
      { schema: FINDINGS, label: `review ${lens.key} r${reviewRound}`, phase: 'Review' },
    ).then(r => (r ? r.findings.map(f => Object.assign({}, f, { lens: lens.key })) : []))
  ))).filter(Boolean).flat()

  const fresh = found.filter(f => !seen.has(keyOf(f)))
  fresh.forEach(f => seen.add(keyOf(f)))
  if (!fresh.length) {
    log(`review round ${reviewRound}: nothing new`)
    break
  }

  const judged = await parallel(fresh.map((f, i) => () =>
    parallel([0, 1].map(k => () =>
      agent(
        `${CONTEXT}

A reviewer claims this problem in block ${BLOCK}:
${JSON.stringify(f, null, 2)}

Try to refute it. Read the code and, where possible, run or write a quick test to check it (do not commit anything, and leave the working tree as you found it). Return refuted=true only if the claim is wrong or does not matter for correctness, security or the PRD.${k === 1 ? ' Look especially for existing tests or code paths that already handle it.' : ''}`,
        { schema: VERDICT, label: `refute ${f.lens} ${i}.${k}`, phase: 'Review' },
      )
    )).then(votes => ({ f, real: votes.filter(Boolean).filter(v => !v.refuted).length >= 1 }))
  ))

  const confirmed = judged.filter(Boolean).filter(j => j.real).map(j => j.f)
  log(`review round ${reviewRound}: ${fresh.length} new, ${confirmed.length} confirmed`)

  // Confirmed findings become tasks, built in parallel in worktrees and merged through the queue.
  const fixTasks = confirmed.map((f, i) => ({
    id: `R${reviewRound}-${i + 1}`,
    title: `review: ${f.title}`.slice(0, 120),
    goal: `Fix this confirmed review finding in block ${BLOCK}: ${JSON.stringify(f)}. Fix the cause, never the check.`,
    acceptance: ['a regression test that fails before the fix and passes after it', '"composer check" is green'],
    dependsOn: [],
  }))
  const fixResults = await runTasks(fixTasks, 'Review')
  fixResults.forEach((r, i) => fixed.push({ finding: confirmed[i].title, severity: confirmed[i].severity, status: r ? r.status : 'agent died' }))
}

// ---------------------------------------------------------------- Exit
phase('Exit')

const exit = await agent(
  `${CONTEXT}

Check the exit criteria of block ${BLOCK} on main:
${JSON.stringify(plan.exitCriteria, null, 2)}

Run each one for real and report pass with evidence (command and the relevant output). Also run the full checks ("composer check"). Do not change any files.`,
  { schema: EXIT, label: `exit ${BLOCK}`, effort: 'high' },
)

const blockStatus = exit && exit.allPass && !failedTasks.length && !blockedTasks.length
  ? 'done'
  : (blockedTasks.length && !failedTasks.length ? 'blocked' : 'incomplete')

const progress = await agent(
  `${CONTEXT}

Update PROGRESS.md for block ${BLOCK} in ${REPO} and commit it with message "${BLOCK}: progress". Never push.

Result:
- block status: ${blockStatus}
- tasks: ${JSON.stringify(results)}
- blocked tasks: ${JSON.stringify(blockedTasks)}
- plan blockers: ${JSON.stringify(plan.blockers || [])}
- regression gate: ${JSON.stringify(gate)}
- review fixes: ${JSON.stringify(fixed)}
- exit criteria: ${JSON.stringify(exit)}

Do this:
1. Set the block's row to ${blockStatus}. If it is done, set the next block that is todo to next.
2. Replace "Seneste kørsel" with a short summary: block, status, tasks done, failed and blocked, integration failures, review findings fixed, failing exit criteria.
3. The merge queue has already written each merged task's entries (under "Til review af Sylvester" with GUARDRAILS 7.3, "Tolkninger" and "Kontroller kørt") and held them to "composer progress:check"; do not repeat them. Add new blockers under "Blokeret" with what they block, and the interpretations and items for human review of the results above that are not there yet. Keep existing entries unless they are resolved.
4. Update "Kontroller kørt" with the latest result per check, including the CI wall time against the 15-minute budget.
5. List any worktree or wip/${BLOCK}-* branch left behind for a task that was not merged, so the next run can reuse or remove it.
6. Leave the STATUS line as it is; the main session decides it.`,
  { schema: PROGRESS_RESULT, label: 'update PROGRESS.md', effort: 'medium' },
)

return {
  block: BLOCK,
  status: blockStatus,
  nextBlock: progress ? progress.nextBlock : null,
  tasks: results.map(t => ({ id: t.id, status: t.status })),
  failedTasks: failedTasks.map(t => ({ id: t.id, failures: t.failures })),
  blockedTasks,
  blockers: plan.blockers || [],
  regressionGate: gate ? { pass: gate.pass, failures: gate.failures } : null,
  reviewFixes: fixed,
  exitCriteria: exit ? exit.criteria : null,
}
