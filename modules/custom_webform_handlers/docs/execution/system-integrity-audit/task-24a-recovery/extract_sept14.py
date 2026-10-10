import json, re, os, sys

F = r"C:/Users/mo737/.codex/sessions/2026/09/14/rollout-2026-09-14T00-52-34-01a09e42-8471-7440-8d62-40d2920065dc.jsonl"

def walk(o):
    if isinstance(o, dict):
        for v in o.values():
            yield from walk(v)
    elif isinstance(o, list):
        for v in o:
            yield from walk(v)
    elif isinstance(o, str):
        yield o

# Collect the largest read blob per target file (numbered `rg -n "^"` dumps).
targets = ["DispatchPlanner.php", "DispatchCheckoutManager.php",
           "DispatchCheckoutPlannerInterface.php"]
best = {t: "" for t in targets}

for line in open(F, encoding="utf-8"):
    try:
        rec = json.loads(line)
    except Exception:
        continue
    p = rec.get("payload", {})
    it = p.get("item", {}) if isinstance(p, dict) else {}
    if not isinstance(it, dict) or it.get("type") != "CommandExecution":
        continue
    cmd = " ".join(walk(it.get("command", [])))
    stdout = it.get("stdout", "") or ""
    for t in targets:
        # a full-file dump: command names the file and stdout has a numbered <?php line 1
        if t in cmd and re.search(r"(^|\n)\s*1[:\|]<\?php", stdout):
            if len(stdout) > len(best[t]):
                best[t] = stdout

outdir = r"C:/Users/mo737/ppu/modules/custom_webform_handlers/docs/execution/system-integrity-audit/task-24a-recovery/sept14-reads"
os.makedirs(outdir, exist_ok=True)
for t, blob in best.items():
    open(os.path.join(outdir, t + ".txt"), "w", encoding="utf-8").write(blob)
    print(t, "->", len(blob), "chars")
