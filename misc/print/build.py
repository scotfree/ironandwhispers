"""Render the files The Game Crafter needs to print Iron and Whispers.

Run from the repo root:
    sim/.venv/bin/python misc/print/build.py            # everything
    sim/.venv/bin/python misc/print/build.py cards board

Writes PNGs to misc/print/out/ (gitignored). Each piece is laid out as an HTML
page and rendered with headless Chrome at exactly the pixel size The Game
Crafter's template has, so the blackletter, the silhouettes in img/ and the
colours are the same ones the client and rules.html use. The rules are
rules.html itself, printed to Letter by Chrome and rasterised by PDFKit.

Every number on the board comes from maps/ and scenarios/; the deck from
data/cards.json and scenarios/baseline.json.

Sizes come from https://www.thegamecrafter.com/api/tgc/products. All of them
are 300 dpi with an eighth of an inch of bleed on each side, and a safe zone
an eighth inside the cut.
"""
import json
import re
import subprocess
import sys
import tempfile
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parents[2]
HERE = Path(__file__).resolve().parent
OUT = HERE / 'out'
CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'

DPI = 300
BLEED = 38          # 1/8" — the cut line on every template
SAFE = 75           # 1/4" from the image edge — the dotted line

EMPIRE, REBELS, PRESENCE = '#6b3fa0', '#2e7d4f', '#f2c230'
INK, PAPER = '#22201d', '#efe7d7'
GOLD_INK, NIGHT = '#f4dea8', '#160e08'   # the title art's letters and their outline

# The 20" board, because the 18" QuadFoldBoard (5475px) leaves 0.48" above two
# poker cards for a location's name and no room for its roofline.
BOARD, BOARD_PRODUCT = 6075, 'LargeQuadFoldBoard, 20 x 20"'


def load(path):
    return json.loads((ROOT / path).read_text())


SCENARIO = load('scenarios/baseline.json')
MAP = load(f"maps/{SCENARIO['map']}.json")
CARDS = load('data/cards.json')

FONT_CSS = f"""
@font-face {{
    font-family: 'UnifrakturCook';
    src: url('{(ROOT / 'fonts/UnifrakturCook-Bold.ttf').as_uri()}') format('truetype');
    font-weight: 700;
}}
* {{ box-sizing: border-box; margin: 0; padding: 0; }}
html, body {{ overflow: hidden; }}
.bl {{ font-family: 'UnifrakturCook', serif; font-weight: 700; }}
"""


def art_symbols():
    """The <symbol>s rules.html carries, generated there from img/*.svg.

    Reused rather than regenerated so the print files cannot drift from the
    manual. The strokes are written as 2px for a screen; scale them here.
    """
    html = (ROOT / 'rules.html').read_text()
    block = html.split('<!-- art:begin')[1].split('<!-- art:end')[0]
    block = block.split('-->', 1)[1]
    block = block.replace('stroke-width:2px', 'stroke-width:var(--art-sw,2px)')
    # The board stretches the location silhouettes to its own proportions.
    return block.replace('<symbol id="art-town"', '<symbol preserveAspectRatio="none" id="art-town"') \
                .replace('<symbol id="art-city"', '<symbol preserveAspectRatio="none" id="art-city"')


def page(width, height, body, css=''):
    return f"""<!doctype html><html><head><meta charset="utf-8"><style>
{FONT_CSS}
html, body {{ width: {width}px; height: {height}px; }}
{css}
</style></head><body>
<svg width="0" height="0" style="position:absolute">{art_symbols()}</svg>
{body}
</body></html>"""


def render(name, width, height, html, transparent=False):
    """Screenshot an HTML page at exactly width x height pixels."""
    OUT.mkdir(exist_ok=True)
    target = OUT / f'{name}.png'
    extra = ['--default-background-color=00000000'] if transparent else []
    with tempfile.TemporaryDirectory() as tmp:
        src = Path(tmp) / 'page.html'
        src.write_text(html)
        subprocess.run([CHROME, '--headless=new', '--disable-gpu', '--hide-scrollbars',
                        '--force-device-scale-factor=1', '--virtual-time-budget=3000',
                        *extra, f'--window-size={width},{height}', f'--screenshot={target}',
                        src.as_uri()], check=True, capture_output=True)
    size = Image.open(target).size
    assert size == (width, height), f'{name}: rendered {size}, wanted {(width, height)}'
    print(f'  {target.relative_to(ROOT)}  {width}x{height}')
    return target


# ---- cards ---------------------------------------------------------------

CARD_W, CARD_H = 825, 1125

CARD_CSS = f"""
.card {{ position: relative; width: {CARD_W}px; height: {CARD_H}px; background: {PAPER}; }}
.title {{ position: absolute; top: 112px; width: 100%; text-align: center;
          font-size: 150px; line-height: 1; color: {REBELS}; }}
.disc {{ position: absolute; left: 50%; top: 560px; transform: translate(-50%, -50%);
         width: 500px; height: 500px; border-radius: 50%; background: {PRESENCE};
         border: 12px solid #ae8c17; display: flex; align-items: center; justify-content: center; }}
.value {{ font-size: 370px; line-height: 1; color: #000; transform: translateY(-18px); }}
.plus {{ font: bold 250px/1 Georgia, serif; vertical-align: 60px; margin-right: 10px; }}
.note {{ position: absolute; bottom: 205px; width: 100%; text-align: center;
         font: italic 46px/1.2 Georgia, serif; color: {INK}; }}
.corner {{ position: absolute; width: 120px; height: 120px; border-radius: 50%;
           background: {PRESENCE}; border: 5px solid #ae8c17; display: flex;
           align-items: center; justify-content: center; font-size: 80px; color: #000; }}
.corner span {{ transform: translateY(-6px); }}
.tl {{ left: 92px; top: 92px; }}
.br {{ right: 92px; bottom: 92px; transform: rotate(180deg); }}
"""


def card_face(card_type):
    presence = CARDS[card_type]['presence']
    note = 'a decoy, worth nothing' if presence == 0 else f'worth {presence} presence'
    body = f"""<div class="card">
  <div class="corner tl bl"><span>{presence}</span></div>
  <div class="corner br bl"><span>{presence}</span></div>
  <div class="title bl">Agent</div>
  <div class="disc"><div class="value bl"><span class="plus">+</span>{presence}</div></div>
  <div class="note">{note}</div>
</div>"""
    return page(CARD_W, CARD_H, body, CARD_CSS)


def card_back():
    """Purple and green rings a little apart: the moire is the interference.

    The centre is the face-down agent's mark from the client: a gold disc with
    a question in it, presence not yet known.
    """
    cx, cy, gap = CARD_W / 2, CARD_H / 2, 34
    rings = lambda x, colour: ''.join(
        f'<circle cx="{x}" cy="{cy}" r="{r}" fill="none" stroke="{colour}" stroke-width="7"/>'
        for r in range(8, 900, 18))
    body = f"""<svg width="{CARD_W}" height="{CARD_H}" viewBox="0 0 {CARD_W} {CARD_H}">
  <rect width="100%" height="100%" fill="{PAPER}"/>
  <g opacity="0.9">{rings(cx - gap, EMPIRE)}</g>
  <g opacity="0.75">{rings(cx + gap, REBELS)}</g>
  <circle cx="{cx}" cy="{cy}" r="150" fill="{PRESENCE}" stroke="#ae8c17" stroke-width="10"/>
  <text x="{cx}" y="{cy + 72}" text-anchor="middle" font-family="Georgia, serif" font-weight="bold" font-size="210" fill="#000">?</text>
</svg>"""
    return page(CARD_W, CARD_H, body)


def build_cards():
    print('cards (PokerDeck, 825x1125)')
    for card_type, count in SCENARIO['deck'].items():
        render(f'card-{card_type}-x{count}', CARD_W, CARD_H, card_face(card_type))
    render('card-back', CARD_W, CARD_H, card_back())


# ---- board ---------------------------------------------------------------

SLOT_W, SLOT_H = 780, 1080      # a poker card, 2.6 x 3.6", with room to spare


def board_html(size):
    """Twelve locations on a 3 x 4 lattice, each with room for both piles.

    The folds cross at the centre. The horizontal one falls between rows two
    and three; the vertical one runs down the middle column, so each location
    is laid out with the gap between its two card spaces on the centre line.
    """
    towns = MAP['towns']
    cols = max(t['x'] for t in towns) + 1
    rows = max(t['y'] for t in towns) + 1
    inner = size - 2 * SAFE
    cell_w, cell_h = inner / cols, inner / rows
    pad, slot_gap = 24, 40
    box_w = 2 * SLOT_W + slot_gap + 2 * pad
    gap_v = 60                                   # the least room left for a road between rows
    room = cell_h - gap_v - SLOT_H - 2 * pad     # what is left for the name and the roofline
    band = min(190, room)                        # the name, inside the walls
    roof = max(0, min(140, room - band))         # the silhouette's roofline, above them
    box_h = band + SLOT_H + 2 * pad
    assert band >= 100, f'{size}px board leaves a {band}px name band'

    def centre(t):
        return SAFE + (t['x'] + 0.5) * cell_w, SAFE + (t['y'] + 0.5) * cell_h

    by_id = {t['id']: t for t in towns}
    roads = []
    for a, b in MAP['edges']:
        (x1, y1), (x2, y2) = centre(by_id[a]), centre(by_id[b])
        roads.append(f'<line x1="{x1}" y1="{y1}" x2="{x2}" y2="{y2}"/>')

    # The silhouettes put their walls at x = 3 and 117 of 120 and the shoulder,
    # where roof meets wall, at y = 26 of 104; the highest point is near y = 6.
    # Map the walls onto the box's sides and the roof into `roof` pixels above
    # it, and clip off everything below the shoulder: the walls are the box.
    sw = 6                                       # stroke width in pixels at print size
    sx, sy = box_w / 114, roof / 20
    locations = []
    for t in towns:
        cx, cy = centre(t)
        left, top = cx - box_w / 2, cy - (box_h - roof) / 2
        art = 'art-city' if t['production'] > 0 else 'art-town'
        builds = (f'<span class="builds">builds {t["production"]}'
                  f' <svg class="pawn" viewBox="0 0 24 32"><use href="#art-pawn"/></svg></span>'
                  if t['production'] > 0 else '')
        roofline = '' if roof == 0 else f"""
    <clipPath id="roof-{t['id']}"><rect x="{-sw}" y="{-roof - sw}" width="{box_w + 2 * sw}" height="{roof + 2 * sw}"/></clipPath>
    <g clip-path="url(#roof-{t['id']})">
      <use href="#{art}" x="{-3 * sx}" y="{-26 * sy}" width="{120 * sx}" height="{104 * sy}"/>
    </g>"""
        locations.append(f"""
<div class="loc" style="left:{left}px;top:{top}px;width:{box_w}px;height:{box_h}px">
  <svg class="frame" width="{box_w}" height="{box_h}">
    <rect x="0" y="0" width="{box_w}" height="{box_h}"
          fill="var(--art-fill)" stroke="var(--art-stroke)" stroke-width="{sw}"/>{roofline}
  </svg>
  <div class="band" style="height:{band + pad}px">
    <span class="name bl">{t['label']}</span>
    <span class="facts">supply {t['supply']}{builds}</span>
    <span class="troops"><svg class="pawn" viewBox="0 0 24 32"><use href="#art-pawn"/></svg>troops</span>
  </div>
  <div class="slot down" style="left:{pad}px;top:{band + pad}px">face down</div>
  <div class="slot up" style="left:{pad + SLOT_W + slot_gap}px;top:{band + pad}px">face up</div>
</div>""")

    name_px = round(min(band * 0.72, 150))
    css = f"""
body {{ position: relative; background: #e6e4dc; --art-sw: {sw}px; }}
.roads {{ position: absolute; left: 0; top: 0; }}
.roads line {{ stroke: #8f877b; stroke-width: 34; stroke-linecap: round; }}
.loc {{ position: absolute; --art-fill: #fbfaf7; --art-stroke: {INK}; }}
.frame {{ position: absolute; left: 0; top: 0; overflow: visible; }}
.band {{ position: absolute; left: 0; right: 0; top: 0; display: flex; align-items: flex-end;
         justify-content: space-between; padding: 0 {pad + 30}px {pad // 2}px; }}
.name {{ font-size: {name_px}px; line-height: 1; color: {INK}; }}
.facts {{ font: italic {round(name_px * 0.42)}px/1 Georgia, serif; color: #6a6258; padding-bottom: 10px; }}
.facts {{ margin-left: auto; margin-right: 36px; }}
.troops {{ width: 520px; height: {band - 30}px; border: 6px dashed #b6a6cb; border-radius: {band}px;
           background: #f3eff8; display: flex; align-items: center; justify-content: center; gap: 18px;
           font: italic {round(name_px * 0.42)}px/1 Georgia, serif; color: #9c8db3; }}
.troops .pawn {{ --art-fill: #ddd3ea; --art-stroke: #b6a6cb; width: 60px; height: 80px; }}
.builds {{ margin-left: 24px; color: {EMPIRE}; font-style: normal; font-weight: bold; }}
.pawn {{ width: {round(name_px * 0.36)}px; height: {round(name_px * 0.48)}px; vertical-align: -6px;
         --art-fill: {EMPIRE}; --art-stroke: #4a2b70; --art-sw: 2px; }}
.slot {{ position: absolute; width: {SLOT_W}px; height: {SLOT_H}px; border-radius: 36px;
         border: 6px dashed #b9b0a1; display: flex; align-items: center; justify-content: center;
         font: italic 54px Georgia, serif; color: #b9b0a1; }}
.slot.down {{ background: repeating-linear-gradient(45deg, #dfece4, #dfece4 18px, #f1f6f3 18px, #f1f6f3 36px); }}
"""
    body = (f'<svg class="roads" width="{size}" height="{size}">{"".join(roads)}</svg>'
            + ''.join(locations))
    return page(size, size, body, css), band


def build_board():
    html, band = board_html(BOARD)
    print(f'board ({BOARD_PRODUCT}, {BOARD}x{BOARD}; name band {band}px = {band / DPI:.2f}")')
    render('board', BOARD, BOARD, html)


# ---- box -----------------------------------------------------------------

BOX = 5925
# Fold lines measured from TGC's overlays/largestoutbox-{top,bottom}.png.
LID_FACE, LID_SIDE = (1294, 4629), 373       # top face span; outer edge of the side panels
BASE_FACE, BASE_SIDE = (1329, 4595), 409


def side_panels(face, side, text, size):
    """The four side panels around a face, title reading the right way up.

    A panel hangs from the face's edge, so seen from outside its top is the
    fold: the panel above the face in the flat template reads upside down.
    """
    a, b = face
    mid, depth = (a + b) / 2, a - side
    panels = [  # (centre x, centre y, rotation)
        (mid, side + depth / 2, 180), (mid, b + depth / 2, 0),
        (side + depth / 2, mid, 90), (b + depth / 2, mid, -90)]
    return ''.join(
        f'<div class="side bl" style="left:{x}px;top:{y}px;font-size:{size}px;'
        f'transform:translate(-50%,-50%) rotate({r}deg)">{text}</div>'
        for x, y, r in panels)


BOX_CSS = f"""
body {{ position: relative; background: {NIGHT}; }}
.side {{ position: absolute; white-space: nowrap; color: {GOLD_INK}; line-height: 1;
         text-shadow: 0 0 30px #000, 0 0 12px #000; }}
.face {{ position: absolute; }}
"""


def build_box():
    print(f'box (LargeStoutBox, {BOX}x{BOX}, top and bottom)')
    a, b = LID_FACE
    art_px = b - a + 2 * 12           # a few pixels over each fold, never a gap
    art = OUT / 'box-art-upscaled.png'
    OUT.mkdir(exist_ok=True)
    Image.open(ROOT / 'misc/iaw_square.png').convert('RGB').resize(
        (art_px, art_px), Image.LANCZOS).save(art)
    top = (f'<img class="face" src="{art.as_uri()}" style="left:{a - 12}px;top:{a - 12}px;'
           f'width:{art_px}px;height:{art_px}px">'
           + side_panels(LID_FACE, LID_SIDE, 'Iron and Whispers', 380))
    render('box-top', BOX, BOX, page(BOX, BOX, top, BOX_CSS))

    a, b = BASE_FACE
    deck = SCENARIO['deck']
    cards = ', '.join(f"{n} {CARDS[t]['label']}" for t, n in deck.items())
    turns = sum(deck.values()) // SCENARIO['hand_size']
    back = f"""
<div class="face" style="left:{a}px;top:{a}px;width:{b - a}px;height:{b - a}px;
     padding:300px 260px;color:{GOLD_INK};text-align:center;font-family:Georgia,serif">
  <div class="bl" style="font-size:330px;line-height:1.05">Iron and Whispers</div>
  <p style="font-size:110px;font-style:italic;margin:90px 0 160px">
    A game of occupation and sedition, for two players</p>
  <p style="font-size:92px;line-height:1.6">One side marches its troops in the open.<br>
    The other seeds agents in secret.<br>Whoever holds more presence in a place wins it.</p>
  <p style="font-size:80px;line-height:1.6;margin-top:200px;color:#c9b88a">
    2 players &middot; {turns} turns<br>
    A folding board of {len(MAP['towns'])} locations &middot; {sum(deck.values())} agent cards
    ({cards})<br>Troop tokens &middot; the rules</p>
</div>""" + side_panels(BASE_FACE, BASE_SIDE, 'Iron and Whispers', 380)
    render('box-bottom', BOX, BOX, page(BOX, BOX, back, BOX_CSS))


# ---- rules ---------------------------------------------------------------

LETTER = (2550, 3300)    # TGC Document: 8.5 x 11", no bleed

def build_rules():
    """rules.html, printed by Chrome to Letter, one PNG per page.

    The manual's own print stylesheet does the layout; only the paper size
    and the margins are overridden, because it is written for A4.
    """
    print('rules (Document, 2550x3300 per page)')
    OUT.mkdir(exist_ok=True)
    for old in OUT.glob('rules-p*.png'):
        old.unlink()
    html = (ROOT / 'rules.html').read_text()
    # h2 carries its section number as a block inside it, and Chrome will break
    # between the two, stranding "THREE" at the foot of a page.
    override = ('<style>@media print { @page { size: 8.5in 11in; margin: 0.6in 0.6in 0.7in; }'
                ' h2 { break-inside: avoid; } }</style>\n</head>')
    html = html.replace('</head>', override, 1)
    pdf = OUT / 'rules.pdf'
    with tempfile.NamedTemporaryFile('w', suffix='.html', dir=ROOT, delete=False) as f:
        f.write(html)            # beside rules.html, so fonts/ resolves the same way
        src = Path(f.name)
    try:
        subprocess.run([CHROME, '--headless=new', '--disable-gpu', '--no-pdf-header-footer',
                        '--virtual-time-budget=3000', f'--print-to-pdf={pdf}', src.as_uri()],
                       check=True, capture_output=True)
    finally:
        src.unlink()
    subprocess.run(['swift', str(HERE / 'pdf-pages.swift'), str(pdf), str(OUT),
                    str(LETTER[0]), str(LETTER[1])], check=True)
    pages = sorted(OUT.glob('rules-p*.png'))
    for p in pages:
        assert Image.open(p).size == LETTER, p
        print(f'  {p.relative_to(ROOT)}')
    print(f'  {len(pages)} pages = {(len(pages) + 1) // 2} double-sided Documents')


# ---- shop ----------------------------------------------------------------

def build_shop():
    """The shop's logo (350x150, transparent) and ad (216x150)."""
    print('shop images')
    logo = f"""<div class="bl" style="width:350px;height:150px;display:flex;flex-direction:column;
        align-items:center;justify-content:center;color:{GOLD_INK};line-height:0.95;
        -webkit-text-stroke:2px {NIGHT};paint-order:stroke fill;text-shadow:0 0 6px #000">
      <div style="font-size:52px">Iron</div><div style="font-size:24px">and</div>
      <div style="font-size:52px">Whispers</div></div>"""
    html = page(350, 150, logo, 'html, body { background: transparent; }')
    target = render('shop-logo', 350, 150, html, transparent=True)
    assert Image.open(target).mode == 'RGBA', 'shop logo came out opaque'

    art = Image.open(ROOT / 'misc/iaw_square.png').convert('RGB')
    w, h = art.size
    crop_h = round(w * 150 / 216)
    top = round(h * 0.02)
    art.crop((0, top, w, top + crop_h)).resize((216, 150), Image.LANCZOS).save(OUT / 'shop-ad.png')
    print(f'  {(OUT / "shop-ad.png").relative_to(ROOT)}  216x150')


STEPS = {'cards': build_cards, 'board': build_board, 'box': build_box,
         'rules': build_rules, 'shop': build_shop}

if __name__ == '__main__':
    wanted = sys.argv[1:] or ['cards', 'board', 'box', 'rules', 'shop']
    for step in wanted:
        STEPS[step]()
