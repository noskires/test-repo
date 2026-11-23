---
name: pss-team
description: Start the PSS agent team (product-owner, dev-lead, qa, releaser) on a goal, wired PO -> dev -> QA -> PR+merge, with a fresh dev-lead and qa per issue. Use when the user types /pss-team or asks to start/run the team.
argument-hint: "[goal, e.g. 'build the next ready-for-agent issue' or 'break down the PRD']"
---

Start the PSS agent team on this goal: $ARGUMENTS
(If no goal was given, use: "build the next ready-for-agent issue from .scratch/pss-mvp/issues/".)

Why this shape: a teammate's whole history is re-sent on every call. One long-lived dev-lead across 17 issues reached a 729k-token context and about 111M cache-read tokens, so per-issue roles are spawned fresh and all state lives in files.

0. Read `.scratch/pss-mvp/epic.md` "Resume here" and tell the user where things stand (last merged, ready now, waiting on the human, doc follow-ups) before spawning anyone.
1. Run `gh auth status`. If gh is missing or logged out, tell the user (`! winget install --id GitHub.cli`, then `! gh auth login`), but keep going: the releaser stops by itself without gh.
2. Spawn the long-lived roles with the Agent tool, setting `subagent_type` and `name` to the role:
   - `product-owner`: "<goal>. Pick or write the issue(s), then send the next ready-for-agent issue path to the team lead." If the goal names a specific issue, tell it to hand that one off.
   - `releaser`: "Wait for a QA PASS, then follow your role instructions."
3. Per issue, when product-owner hands you an issue path:
   a. Spawn a FRESH `dev-lead` and a FRESH `qa` in one message (subagent_type and name = role). The prompt is just the issue path plus "Follow your role instructions." For an issue product-owner calls a small chore, you may pass `model: "sonnet"` to dev-lead.
   b. Don't send git-sync or "main is at X" messages. Dev-lead pulls main itself. Message a teammate only with something it can't read from files (a human decision for the in-flight issue).
   c. When the releaser reports the merge, relay the PR URL and merge hash, then stop `dev-lead` and `qa` with TaskStop. Don't message product-owner: the releaser's merge report already tells it to pick the next issue, and a second message wakes it twice.
   d. Every ~5 issues (or sooner if a usage-limit pause happened), restart `product-owner` and `releaser` between issues the same way: TaskStop, then spawn fresh. Their state lives in the issue files, spec.md and git.
4. Tell the user who is doing what, then relay hand-offs, questions for the human and final results. Don't relay idle pings that add nothing new.
