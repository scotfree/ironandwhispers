# Handoff: ironandwhisper-20261103-1307-alpha-ui-guidelines

- Repo / branch / HEAD: scotfree/ironandwhispers, `rename-ironandwhispers`. This wrapup commit is pushed. **The rename code is still uncommitted in the working tree.**
- Session: 2026-11-01 to 11-03. Renamed to `ironandwhispers`, moved SFTP to an SSH key, reached private alpha, read BGA's UX guidelines.

## Where things stand
- Private alpha reached from the `ironandwhispers` project (reported by Scot 2026-11-03). The name is locked.
- Deployed to BGA from the working tree. The deployed rename exists in git only as the `CLAUDE.md` and `design.md` edits in this wrapup commit.
- Tests when last run, 2026-11-02: PHP 173 passed; simulator 185 passed, 4 skipped. Not re-run since.
- BGA reported a dark mode popup bug and sent UX/UI guidelines. Compared with the client by reading source only. No code changed for either.

## Next step
Get Scot's answer on question 1, then commit the rename and merge it. Then discuss the dark mode approach (#21) on its own branch before coding.

## Open questions for Scot
1. **Branch:** commit and merge the rename to `main` first? A merge publishes the updated manual links.
2. **Scope:** dark mode now, then the #22 checklist, then #23 one decision at a time?
3. **Blackletter:** keep it on the title image and frame titles only, and ask BGA whether that passes?
4. **Resolution card:** replace it with on-board animation, or keep it as a skippable scoring stage? Suggested: replace.
5. **Start card:** drop it? Suggested: yes.
6. **Art:** commit `misc/iaw_banner.jpg`, `iaw_box.png`, `iaw_icon.png`, `iaw_title.png`? They are untracked.
7. **ccom backup:** download `ccom` from the BGA server? GitHub `scotfree/c-com` has only a README, so the server may hold the only copy.
8. **Old project:** ask BGA whether to remove `ironandwhisper` now the new one is confirmed?
9. **Old Keychain entry:** `security delete-generic-password -s bga-studio-sftp -a scotfree` is safe to run. Not done.
10. **Checkout folder:** rename the local folder to match? The Claude memory path depends on it, so do it at the end of a session.

## Decisions this session
- The name is "Iron and Whispers", and every identifier is `ironandwhispers`, at BGA's requirement.
- No spaces in the project name. `display_name` showing the raw name in Studio also appears on the old project, so it was left alone.
- SFTP login by SSH key, `~/.ssh/bga_studio_ed25519`, because the password stopped working.
- `misc/bga-ux-guidelines.pdf` stays untracked: the repo is public and it is BGA's document. Claude's call; Scot asked only to keep the file.

## Issues filed / relevant
- #21 Dark mode popup and missing dark theme
- #22 BGA's UX checklist before beta
- #23 UI refresh decisions (the list of what is being considered)
- #24 Print-on-demand and a BGG entry
- #4 Animation, #17 phone layout

## Gotchas
- `php` is not on the shell PATH: use `~/.local/bin/php`.
- Never blanket `--delete` a deploy. The server holds BGA's own files.
- The deploy syncs the working tree, so keep art in `misc/`.
- Other branches (`advanced-rules-deck-arrangement`, `gpt-experiment`, `gpt-empire`, `location-value`) predate the rename and will conflict.
- Still unverified: the dark mode cause (not reproduced; BGA didn't name the popup), and that the displayed name became "Iron and Whispers" after alpha.
- If redeploying `zoomquest` or `ccom`: set `bgg_id` back to 0; 2389 is Othello, inherited from the tutorial.
- Unread: BGA's "Main Concepts" document, and the example images in the guidelines PDF.

## Verify state on pickup
```
git log -2 --oneline
git status --short | wc -l
ls misc/bga-ux-guidelines.pdf
gh issue view 23 --repo scotfree/ironandwhispers
~/.local/bin/php tests/run.php | tail -2
```
