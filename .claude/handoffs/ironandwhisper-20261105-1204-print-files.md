# Handoff: ironandwhisper-20261105-1204-print-files

- Repo / branch / HEAD: scotfree/ironandwhispers, `main`. Everything is pushed with this wrapup commit. The only untracked file is `misc/bga-ux-guidelines.pdf`, deliberately.
- Session: 2026-11-04 to 11-05. Merged the rename, then generated The Game Crafter print files.

## Where things stand
- The rename is on `main` (`52a76ee`), including the SSH key deploy change. BGA still runs the same code, deployed 2026-11-02. Nothing was deployed this session.
- `misc/print/build.py` renders every print file into gitignored `misc/print/out/` (`62cac7c`):
  - cards: Agent +0 x24, Agent +1 x36, moiré back
  - 20" Large Quad-Fold board, with a troop spot per location
  - Large Stout box, top and bottom
  - rules as 11 Letter pages
  - shop logo and ad
- `misc/print/check.py` lays a file over The Game Crafter's template. Cards, box and board were all checked against it.
- `rules.html` lost its two screen-only lines, and is republished on GitHub Pages. The copy on BGA updates at the next deploy.
- Metadata Manager art is committed in `misc/` (`3f8f2e6`).
- Tests on 2026-11-05: PHP 173 passed; simulator 185 passed, 4 skipped.

## Next step
Scot chooses between the print-on-demand order (#24 "Still to do") and dark mode (#21). For dark mode, discuss the approach on its own branch before coding. For print:
1. Choose stock parts for troops, resolved markers and scoring.
2. Check the price in the quote tool.
3. Find out whether a card image can carry a quantity.
4. Upload and order a proof copy.

## Open questions for Scot
1. **Next thread:** print order (#24), or dark mode (#21)?
2. **Scope:** dark mode, then the #22 checklist, then #23 one decision at a time? Carried over.
3. **Blackletter, resolution card, start card:** questions 3–5 of the last handoff, still open on #23.
4. **ccom backup:** download `ccom` from the BGA server? GitHub has only a README.
5. **Old project:** ask BGA whether to remove `ironandwhisper`?
6. **Old Keychain entry:** `security delete-generic-password -s bga-studio-sftp -a scotfree` is safe to run, but hasn't been.
7. **Checkout folder:** rename it to match? The memory path depends on it, so do this at the end of a session.
8. **Duplicate art:** `misc/iaw_box.png` and `iaw_title.png` duplicate files in `misc/art/`. Keep both copies?

## Decisions this session
- The Game Crafter is the print-on-demand service.
- 20 x 20" Large Quad-Fold board, not 18". Two poker-card piles per location leave the 18" 0.48" for a name.
- Cards are typographic: big blackletter on gold discs. The back is purple and green rings in a moiré. The plus sign is Georgia, because the blackletter "+" read as a 4.
- The box lid uses `misc/iaw_square.png`. The rules print to look like `rules.html`, and `rules.html` itself was edited to work on paper.
- Print and `misc/` work goes straight to `main`, because none of it deploys. Scot's call, against my default of a branch.

## Issues filed / relevant
- #25 The box art needs a higher-resolution original for print
- #26 The manual still calls the rebels "the Insurgency" in 14 places
- #24 Print-on-demand and a BGG entry (progress comment added)
- #21 dark mode, #22 UX checklist, #23 UI refresh

## Gotchas
- The Game Crafter's product pages and pricing page return 404 or nothing usable. The real sizes are in `https://www.thegamecrafter.com/api/tgc/products`. Templates are at `/overlays/<product>.png`, and `check.py` fetches them.
- Chrome headless renders the files: `/Applications/Google Chrome.app`. There's no rsvg, ImageMagick or Inkscape. PIL is in `sim/.venv`.
- A Document is one 8.5 x 11" sheet, front and back, with no bleed. Boxes and cards have 1/8" bleed.
- `php` is at `~/.local/bin/php`. Never blanket `--delete` a deploy.

## Verify state on pickup
```
git log -4 --oneline
git status --short
sim/.venv/bin/python misc/print/build.py cards && ls misc/print/out | head
gh issue view 24 --repo scotfree/ironandwhispers --comments | tail -15
```
