"""Lay rendered print files over The Game Crafter's template, to check the cut.

Run from the repo root, after build.py:
    sim/.venv/bin/python misc/print/check.py PokerDeck card-influence0-x24 card-influence1-x36 card-back
    sim/.venv/bin/python misc/print/check.py LargeStoutBox:top box-top
    sim/.venv/bin/python misc/print/check.py LargeStoutBox:bottom box-bottom
    sim/.venv/bin/python misc/print/check.py LargeQuadFoldBoard board

Downloads the product's overlay (red bleed, cut line, blue safe zone, fold
lines) into misc/print/out/templates/ on first use, composites it over each
file side by side, and writes misc/print/out/check-<product>.png at a size
worth looking at. The templates are The Game Crafter's, so they stay out of git
with the rest of out/.
"""
import sys
import urllib.request
from pathlib import Path

from PIL import Image

OUT = Path(__file__).resolve().parent / 'out'
TEMPLATES = OUT / 'templates'
SITE = 'https://www.thegamecrafter.com'


def overlay(product):
    """product is 'PokerDeck', or 'LargeStoutBox:top' for a multi-sided one."""
    name, _, side = product.partition(':')
    file = name.lower() + (f'-{side}' if side else '') + '.png'
    path = TEMPLATES / file
    if not path.exists():
        TEMPLATES.mkdir(parents=True, exist_ok=True)
        request = urllib.request.Request(f'{SITE}/overlays/{file}',
                                         headers={'User-Agent': 'Mozilla/5.0'})
        path.write_bytes(urllib.request.urlopen(request, timeout=60).read())
    image = Image.open(path).convert('RGBA')
    # Thin the opaque bleed tint so the artwork under it still shows.
    image.putalpha(image.split()[3].point(lambda a: 90 if a > 200 else a))
    return image


def main(product, *names):
    template = overlay(product)
    tiles = []
    for name in names:
        art = Image.open(OUT / f'{name}.png').convert('RGBA')
        assert art.size == template.size, f'{name} is {art.size}, template is {template.size}'
        art.alpha_composite(template)
        tiles.append(art.convert('RGB'))
    gap = 20
    sheet = Image.new('RGB', (sum(t.width for t in tiles) + gap * (len(tiles) - 1),
                              tiles[0].height), 'white')
    x = 0
    for tile in tiles:
        sheet.paste(tile, (x, 0))
        x += tile.width + gap
    scale = min(1.0, 2400 / sheet.width)
    target = OUT / f"check-{product.replace(':', '-')}.png"
    sheet.resize((round(sheet.width * scale), round(sheet.height * scale)), Image.LANCZOS).save(target)
    print(target)


if __name__ == '__main__':
    main(*sys.argv[1:])
