---
name: pss-team
description: Start the PSS agent team (product-owner, dev-lead, qa, releaser) on a goal, wired PO -> dev -> QA -> PR+merge. Use when the user types /pss-team or asks to start/run the team.
argument-hint: "[goal, e.g. 'build the next ready-for-agent issue' or 'break down the PRD']"
---

Start the PSS agent team on this goal: $ARGUMENTS
(If no goal was given, use: "build the next ready-for-agent issue from .scratch/pss-mvp/issues/".)

1. Run `gh auth status`. If gh is missing or logged out, tell the user (`! winget install --id GitHub.cli`, then `! gh auth login`), but keep going: the releaser stops by itself without gh.
2. In ONE message, spawn four teammates with the Agent tool. For each, set `subagent_type` and `name` to the role: `product-owner`, `dev-lead`, `qa`, `releaser`. Prompt each one with the goal above, plus:
   - product-owner: "Pick or write the issue(s) for this goal, then hand the next ready-for-agent issue to dev-lead."
   - dev-lead / qa / releaser: "Wait for your hand-off message, then follow your role instructions."
   If the goal names a specific issue, also tell product-owner to send that issue straight to dev-lead.
3. Tell the user who is doing what, then relay hand-offs, questions for the human and final results (PR URL, merge hash) as teammates report. Don't relay idle pings that add nothing new.
