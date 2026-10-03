---
name: cpd
description: Commits the current working tree, pushes to origin main, and deploys to the live Kochbuch server via bin\deploy.bat. Use whenever the user types /cpd, or asks to "commit, push and deploy" / "cpd" in this repo - it's the standard end-of-task shortcut for shipping a finished change in this project, invoked many times per session. Only applies to the Kochbuch repo (c:\dev\www\kochbuch) - bin\deploy.bat doesn't exist elsewhere.
---

# /cpd - commit, push, deploy

The user invoking `/cpd` is itself their authorization to commit, push to `origin main`, and deploy to the live server in one go - don't re-ask for confirmation at each step the way you normally would for a push/deploy. Still apply judgment: if something looks genuinely wrong (see "When to stop" below), stop and explain rather than pushing through.

## 1. Look at what's actually there

Run in parallel: `git status --short`, `git diff` (unstaged) and `git diff --cached` (staged), and `git log --oneline -8`.

If `git status --short` is empty, there's nothing to do - tell the user the working tree is clean and stop here.

## 2. Stage deliberately, not blindly

Stage the files that make up the finished change(s) by name. Don't run `git add -A` or `git add .` - skim the status output first, since an unrelated stray file (a stray log, a half-finished experiment, something that looks like it might hold a secret) has no business riding along in this commit. If something looks out of place, ask rather than silently including or silently dropping it.

If the working tree holds more than one unrelated piece of work (rare, but possible if several things were done back-to-back without an intervening `/cpd`), either combine them into one commit with a body that covers each part, or split into separate commits if they're cleanly separable - use judgment, matching whatever this repo's own recent history looks like for similar situations (check `git log` from step 1).

## 3. Write the commit message

Look at the real `git log` output from step 1, not just the examples below - style drifts over time and the most recent commits are the ground truth. As of this writing the pattern is:

```
<TYPE>: <Short German summary, imperative/noun-phrase style>
```

where `<TYPE>` is `NEW:` (a feature that didn't exist before), `FIX:` (corrects broken behavior), `CHANGE:` (alters existing behavior without fixing a bug), or `ADD:` (extends something existing, e.g. a new field/option). The subject is almost always in German, matching the project's own `todo.md`/`done.md`.

A body is optional - add one (1-3 sentences, German, same tone as `done.md`'s "Gelöst"-entries) only when the *why* genuinely isn't obvious from the diff or the subject line: a non-obvious root cause, a deliberate tradeoff, something a future reader would otherwise have to reconstruct from scratch. Plenty of good commits in this repo are a bare one-line subject - don't pad one out just to seem thorough.

Commit via a heredoc so formatting survives intact, and end the message with the attribution line this session's system reminder specifies (check it - it may not always be the same text):

```bash
git commit -m "$(cat <<'EOF'
FIX: Kurze Zusammenfassung

Optionaler Absatz, der das Warum erklärt, falls nicht offensichtlich.
EOF
)"
```

## 4. Push

`git push origin main`. If this is the first push of a new branch instead of `main` (unusual for this repo, but possible), use `-u` accordingly - check `git branch --show-current` if `main` doesn't look right.

## 5. Deploy

Run `bin\deploy.bat` (PowerShell, from the repo root: `.\bin\deploy.bat`). Let it run to completion - it installs production dependencies, packages the tree, uploads over SSH, and runs any pending migrations remotely. This takes a minute or two; don't interrupt it.

Watch the output for `==> Deploy FAILED.` If that happens, stop and show the user the failure - don't retry blindly or fall back to a different deploy mechanism.

## 6. Report back

One short message: the commit hash + subject, confirmation it's pushed, and the deploy result (including whether any migrations ran - `deploy.bat` prints `Applying ...` lines for each one, or `Nothing to do, schema is up to date.` if none were pending).

## When to stop and ask instead of proceeding

- `git status --short` shows something that looks like a credential, `.env` contents, or other secret-shaped file.
- The diff touches files far outside what the conversation was actually about (possible leftover from an earlier, unrelated experiment).
- `composer test` has visibly not been run this session for a backend change and there's reason to think it might not pass (e.g. the conversation ended mid-fix) - if in doubt, it's cheap to run it before committing.
- Anything about the deploy output looks wrong even if it didn't print `FAILED` (e.g. a migration erroring partway, a `git push` rejected due to a remote change you didn't expect).

In all of these, explain what you saw and let the user decide - that's a different situation from the routine case `/cpd` exists to streamline.
