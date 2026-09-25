export const meta = {
  name: 'cms-milestone',
  description: 'Build one Cbox CMS block from MILESTONES.md: plan, implement with checks, adversarial review, fix, verify exit criteria, update PROGRESS.md',
  whenToUse: 'Autopilot development of laravel-cms. Pass args {block: "M0"} (ids in PROGRESS.md).',
  phases: [
    { title: 'Plan', detail: 'break the block into small ordered tasks with acceptance checks and exit criteria' },
    { title: 'Build', detail: 'implement each task, verify with the checks, fix until green' },
    { title: 'Review', detail: 'lens reviewers find problems, two skeptics try to refute each, confirmed ones are fixed' },
    { title: 'Exit', detail: 'run the exit criteria and record the result in PROGRESS.md' },
  ],
}

const BLOCK = args && args.block
if (!BLOCK) {
  throw new Error('cms-milestone needs args {block: "<id>"}, e.g. {block: "M0"}')
}
const MAX_FIX_ROUNDS = (args && args.maxFixRounds) || 3
const MAX_REVIEW_ROUNDS = (args && args.maxReviewRounds) || 2

const REPO = '/Users/sylvester/Projects/laravel-cms'
const PLAN_REPO = '/Users/sylvester/Projects/cbox-cms'
const CONTEXT = `You work in ${REPO} (git repo, local only, never push). Planning documents: ${PLAN_REPO}/PRD.md (Danish, architecture), ${PLAN_REPO}/GUARDRAILS.md (coding rules), ${PLAN_REPO}/MILESTONES.md (build order and exit criteria). Working state: ${REPO}/PROGRESS.md. The current block is ${BLOCK} as listed in PROGRESS.md and described in MILESTONES.md.`

const TASK = {
  type: 'object',
  properties: {
    id: { type: 'string' },
    title: { type: 'string' },
    goal: { type: 'string' },
    prdRefs: { type: 'array', items: { type: 'string' } },
    acceptance: { type: 'array', items: { type: 'string' } },
    dependsOn: { type: 'array', items: { type: 'string' } },
  },
  required: ['id', 'title', 'goal', 'acceptance'],
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
  },
  required: ['status', 'summary'],
}
const VERIFY = {
  type: 'object',
  properties: {
    pass: { type: 'boolean' },
    failures: { type: 'array', items: { type: 'string' } },
    checksRun: { type: 'array', items: { type: 'string' } },
  },
  required: ['pass', 'failures', 'checksRun'],
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

const VERIFY_RULES = `Run the real checks, do not assume. Use "composer check" if it exists; otherwise run each tool that exists (Pint in test mode, Rector dry-run, PHPStan, Pest, tsc, ESLint). Start the Docker services for tests if needed (Postgres 17 and Valkey via docker compose). Also run the task's acceptance checks. Report pass only if every check you ran is green and every acceptance item is met. Do not change any files.`

// ---------------------------------------------------------------- Plan
phase('Plan')

let plan = await agent(
  `${CONTEXT}

Plan block ${BLOCK}. Read PROGRESS.md, the block in MILESTONES.md, the PRD sections it depends on, GUARDRAILS.md and the current repo state (git log, files). Work that is already committed and green counts as done; plan only what remains.

Break the remaining work into small, ordered tasks that one agent can finish, test and commit in one go (roughly one coherent change each). Order them so each task only depends on earlier ones. Every task needs concrete acceptance items that can be checked by running something (a test, a command, a query), not opinions.

Copy the block's exit criteria from MILESTONES.md into exitCriteria, each with a command or procedure that proves it.

Put decisions reserved for Sylvester (see CLAUDE.md) in blockers, and plan around them where possible. Do not change any files.`,
  { schema: PLAN, label: `plan ${BLOCK}`, effort: 'high' },
)

const critique = await agent(
  `${CONTEXT}

Here is a plan for block ${BLOCK}:
${JSON.stringify(plan, null, 2)}

Act as a completeness critic. Compare it with the block in MILESTONES.md, the PRD sections it builds, and GUARDRAILS.md (actions, DTOs, contracts with fakes and shared contract tests, real Postgres tests, observability, definition of done). List what is missing (work, tests, exit criteria) and what is wrong (wrong order, tasks too big to finish in one go, acceptance items that cannot be checked, scope creep outside the block). Do not change any files.`,
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

log(`${BLOCK}: ${plan.tasks.length} tasks, ${plan.exitCriteria.length} exit criteria, ${(plan.blockers || []).length} blockers`)

// ---------------------------------------------------------------- Build
phase('Build')

const taskResults = []
const blockedTasks = []

async function verifyTask(task, label) {
  return agent(
    `${CONTEXT}

Verify task ${task.id} "${task.title}" of block ${BLOCK}.
Goal: ${task.goal}
Acceptance: ${JSON.stringify(task.acceptance)}

${VERIFY_RULES}`,
    { schema: VERIFY, label, phase: 'Build', effort: 'medium' },
  )
}

for (const task of plan.tasks) {
  const deps = task.dependsOn || []
  const blockedDep = deps.find(d => blockedTasks.includes(d))
  if (blockedDep) {
    blockedTasks.push(task.id)
    log(`${task.id} skipped: depends on blocked ${blockedDep}`)
    taskResults.push({ id: task.id, status: 'blocked', summary: `depends on blocked ${blockedDep}` })
    continue
  }

  let result = await agent(
    `${CONTEXT}

Implement task ${task.id} "${task.title}" of block ${BLOCK}.
Goal: ${task.goal}
PRD references: ${JSON.stringify(task.prdRefs || [])}
Acceptance: ${JSON.stringify(task.acceptance)}
Earlier tasks in this block: ${JSON.stringify(taskResults.map(r => ({ id: r.id, status: r.status, summary: r.summary })))}

Write the code and its tests following GUARDRAILS.md. Run the checks and the acceptance items yourself and fix what fails. When green, commit with message "${BLOCK}-${task.id}: <what>". Never push. Never weaken a check (GUARDRAILS 7.3); if you think a check is wrong, leave it and report it in forHumanReview. If the task needs a decision reserved for Sylvester, stop and return status blocked with the blocker. Report PRD interpretations you made in interpretations.`,
    { schema: TASK_RESULT, label: `build ${task.id}`, phase: 'Build' },
  )

  if (!result || result.status === 'blocked') {
    blockedTasks.push(task.id)
    taskResults.push({ id: task.id, status: 'blocked', summary: result ? result.summary : 'agent died', blocker: result && result.blocker })
    continue
  }

  let verdict = await verifyTask(task, `verify ${task.id}`)
  let round = 0
  while (verdict && !verdict.pass && round < MAX_FIX_ROUNDS) {
    round++
    const fix = await agent(
      `${CONTEXT}

Task ${task.id} "${task.title}" of block ${BLOCK} failed verification.
Failures: ${JSON.stringify(verdict.failures)}
Acceptance: ${JSON.stringify(task.acceptance)}

Fix the cause, not the check (GUARDRAILS 7.3). Add a regression test when the failure is a bug. Run the checks, then commit with message "${BLOCK}-${task.id}: fix <what>". Never push.`,
      { schema: TASK_RESULT, label: `fix ${task.id} #${round}`, phase: 'Build' },
    )
    if (fix) {
      result.interpretations = (result.interpretations || []).concat(fix.interpretations || [])
      result.forHumanReview = (result.forHumanReview || []).concat(fix.forHumanReview || [])
    }
    verdict = await verifyTask(task, `verify ${task.id} #${round}`)
  }

  const green = Boolean(verdict && verdict.pass)
  taskResults.push({
    id: task.id,
    status: green ? 'done' : 'failed',
    summary: result.summary,
    failures: green ? [] : (verdict ? verdict.failures : ['verifier died']),
    interpretations: result.interpretations || [],
    forHumanReview: result.forHumanReview || [],
  })
  log(`${task.id}: ${green ? 'green' : 'still failing after ' + round + ' fix rounds'}`)
}

// ---------------------------------------------------------------- Review
phase('Review')

const LENSES = [
  { key: 'prd', prompt: 'Does the code do what the PRD sections for this block say? Look for missing behaviour, wrong semantics and invariants from PRD 6.5 that are not enforced or not tested.' },
  { key: 'guardrails', prompt: 'Does the code follow GUARDRAILS.md: actions and plans, envelope, DTOs and value objects, no mixed or untyped arrays outside Boundary and Adapter, contracts with fakes that pass the shared contract tests, layers, readonly per category, codecs?' },
  { key: 'correctness', prompt: 'Look for correctness bugs: concurrency and transactions (separate connections, expected versions, idempotency), RLS as the app role, outbox atomicity, time handling, edge cases. Prefer findings you can demonstrate with a failing test.' },
  { key: 'security', prompt: 'Look for security problems: SSRF (all outbound HTTP through laravel-ssrf), secrets in logs or events, authorization outside the pipelines, classification leaks, SQL injection, unsafe defaults.' },
  { key: 'integrity', prompt: 'Check the integrity of the checks themselves in this block\'s commits (git log and diffs): weakened analysis config, excluded paths, skipped or loosened tests, changed snapshots or expectations without reason, @phpstan-ignore outside Boundary and Adapter, generated files edited by hand.' },
]

const seen = new Set()
const fixed = []
const keyOf = f => `${f.file}|${f.title}`.toLowerCase()

for (let reviewRound = 1; reviewRound <= MAX_REVIEW_ROUNDS; reviewRound++) {
  const found = (await parallel(LENSES.map(lens => () =>
    agent(
      `${CONTEXT}

Review the work of block ${BLOCK} (see the commits whose messages start with "${BLOCK}-") through one lens.
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

  for (const f of confirmed) {
    const fix = await agent(
      `${CONTEXT}

Fix this confirmed problem in block ${BLOCK}:
${JSON.stringify(f, null, 2)}

Write a regression test that fails before the fix. Fix the cause, never the check. Run the checks, then commit with message "${BLOCK}-review: <what>". Never push.`,
      { schema: TASK_RESULT, label: `fix review: ${f.title}`.slice(0, 60), phase: 'Review' },
    )
    fixed.push({ finding: f.title, severity: f.severity, status: fix ? fix.status : 'agent died' })
  }
}

// ---------------------------------------------------------------- Exit
phase('Exit')

const exit = await agent(
  `${CONTEXT}

Check the exit criteria of block ${BLOCK}:
${JSON.stringify(plan.exitCriteria, null, 2)}

Run each one for real and report pass with evidence (command and the relevant output). Also run the full checks ("composer check" if it exists). Do not change any files.`,
  { schema: EXIT, label: `exit ${BLOCK}`, effort: 'high' },
)

const failedTasks = taskResults.filter(t => t.status === 'failed')
const blockStatus = exit && exit.allPass && !failedTasks.length && !blockedTasks.length
  ? 'done'
  : (blockedTasks.length && !failedTasks.length ? 'blocked' : 'incomplete')

const progress = await agent(
  `${CONTEXT}

Update PROGRESS.md for block ${BLOCK} and commit it with message "${BLOCK}: progress". Never push.

Result:
- block status: ${blockStatus}
- tasks: ${JSON.stringify(taskResults)}
- blocked tasks: ${JSON.stringify(blockedTasks)}
- plan blockers: ${JSON.stringify(plan.blockers || [])}
- review fixes: ${JSON.stringify(fixed)}
- exit criteria: ${JSON.stringify(exit)}

Do this:
1. Set the block's row to ${blockStatus}. If it is done, set the next block that is todo to next.
2. Replace "Seneste kørsel" with a short summary: block, status, tasks done and failed, review findings fixed, failing exit criteria.
3. Add new blockers under "Blokeret" with what they block, new interpretations under "Tolkninger", and new items under "Til review af Sylvester". Keep existing entries unless they are resolved.
4. Update "Kontroller kørt" with the latest result per check.
5. Leave the STATUS line as it is; the main session decides it.`,
  { schema: PROGRESS_RESULT, label: 'update PROGRESS.md', effort: 'medium' },
)

return {
  block: BLOCK,
  status: blockStatus,
  nextBlock: progress ? progress.nextBlock : null,
  tasks: taskResults.map(t => ({ id: t.id, status: t.status })),
  failedTasks: failedTasks.map(t => ({ id: t.id, failures: t.failures })),
  blockedTasks,
  blockers: plan.blockers || [],
  reviewFixes: fixed,
  exitCriteria: exit ? exit.criteria : null,
}
