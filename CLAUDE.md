# HP Funnel Bridge - agent entry point (read this first)

You are in HP-Funnel-Bridge, part of the HolisticPeople platform. The source of truth is
`HolisticPeople/HP-Codex-Skills@dev` (HP-Roadmap v4.0).

`hp` = `python3 <skills-home>/hp-codex-machine-setup/scripts/hp.py`
(`<skills-home>` is `~/.codex/skills` on Codex or `~/.claude/skills` on Claude Code).

## START (first steps in a new thread)
  1. `hp sync`                 # check your skills are current + install for this runtime
  2. `hp status`               # what THIS repo owns / consumes + its open-loop docs
  3. `hp roadmap "<topic>"`    # before any cross-plugin work: read the canonical plan

## YOUR LANE  (owner lane: `github-discovered`)
OWNS:
  - repo-local plugin behavior to be confirmed by owning lane
MUST NOT OWN:
  - cross-plugin source mutation without registered contract
CONSUMES (advisory - read other plugins only via their public, versioned,
fail-soft contracts; never their internals; no hard coupling):
  - â€”

## READ FIRST
  - HP-Codex-Skills/skills/hp-roadmap/references/roadmaps/hp-dev-phase-current-state-index-2026-06.md

## WHEN YOU FINISH SOMETHING DURABLE
  - Land it as ONE commit: the central plan doc + this repo's pointer (AGENTS.md, docs/plan/parking-lot.md).
  - Owning lanes close their own PRs/branches - HP-Roadmap does not close them for you.

## THE WHOLE MAP (every plugin + what it exposes)
  `HP-Codex-Skills/skills/hp-roadmap/references/hp-plugin-architecture-catalog.md`
  What changed / is it better:  `hp whatsnew`  |  `HP-Codex-Skills/MIGRATION-2026-06.md`

<!-- Generated from the HP Plugin Architecture Registry. Edit the registry entry,
     then regenerate with skills/hp-roadmap/scripts/generate_entry_file.py. -->

