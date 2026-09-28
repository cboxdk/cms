#!/usr/bin/env bash
# Stop guard for autopilot. Blocks a session from stopping while autopilot is on,
# unless a workflow is running (.harness/waiting) or PROGRESS.md says the run is
# complete or blocked. A hard iteration cap stops runaway loops.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
H="$ROOT/.harness"

cat > /dev/null

[ -f "$H/autopilot.on" ] || exit 0
[ -f "$H/waiting" ] && exit 0

if grep -Eq '^STATUS: (complete|blocked)' "$ROOT/PROGRESS.md" 2>/dev/null; then
  rm -f "$H/autopilot.on"
  exit 0
fi

count=$(( $(cat "$H/iterations" 2>/dev/null || echo 0) + 1 ))
echo "$count" > "$H/iterations"
max=$(cat "$H/max-iterations" 2>/dev/null || echo 300)
if [ "$count" -gt "$max" ]; then
  rm -f "$H/autopilot.on"
  exit 0
fi

reason="Autopilot er slået til (stop $count af $max). Stop ikke. Læs PROGRESS.md og tag den første blok med status next, in_progress eller incomplete som ikke afhænger af noget under Blokeret. Opret .harness/waiting og start workflowen med scriptPath $ROOT/.claude/workflows/cms-milestone.js (aldrig med navnet) og args {block: <id>, maxParallel: 4, verifyModel: sonnet, maxReviewRounds: 1}. Er alle blokke done, sæt STATUS: complete i PROGRESS.md. Afhænger alle resterende blokke af en beslutning under Blokeret, sæt STATUS: blocked. Send i begge tilfælde en push-notifikation til Sylvester før du stopper."

# JSON without depending on jq: PHP is on every machine that runs the gates.
if command -v jq > /dev/null 2>&1; then
  jq -n --arg r "$reason" '{decision: "block", reason: $r}'
else
  REASON="$reason" php -r 'echo json_encode(["decision" => "block", "reason" => getenv("REASON")], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;'
fi
