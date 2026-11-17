# Baja las imágenes de los monstruos de los mapas extra desde guides.zigzagmu.ru y las deja en 64x64.
# Uso: mobs.py <carpeta de descarga> <carpeta public/assets/img/monsters>
import html, io, os, re, sys, urllib.request
from html.parser import HTMLParser
from PIL import Image

RAW, OUT = sys.argv[1], sys.argv[2]
os.makedirs(RAW, exist_ok=True)
PAGE = 'https://guides.zigzagmu.ru/p/monster.html'
UA = {'User-Agent': 'Mozilla/5.0'}

WANT = {'alquamos': 'Alquamos', 'mega_crust': 'Mega Crust', 'queen_rainier': 'Queen Rainier', 'drakan': 'Drakan',
 'alpha_crust': 'Alpha Crust', 'phantom_knight': 'Phantom Knight', 'great_drakan': 'Great Drakan',
 'phoenix_of_darkness': 'Phoenix of Darkness', 'death_tree': 'Death Tree', 'forest_orc': 'Forest Orc',
 'death_rider': 'Death Rider', 'blue_golem': 'Blue Golem', 'witch_queen': 'Witch Queen', 'hell_maine': 'Hell Maine',
 'splinter_wolf': 'Splinter Wolf', 'iron_rider': 'Iron Rider', 'satyros': 'Satyros', 'blade_hunter': 'Blade Hunter',
 'kentauros': 'Kentauros', 'gigantis': 'Gigantis', 'berserker': 'Berserker', 'genocider': 'Genocider',
 'twin_tale': 'Twin Tale', 'persona': 'Persona', 'dreadfear': 'Dreadfear',
 'venomous_chain_scorpion': 'Venomous Chain Scorpion', 'bone_scorpion': 'Bone Scorpion', 'orcus': 'Orcus',
 'gollock': 'Gollock', 'crypta': 'Crypta', 'crypos': 'Crypos', 'condra': 'Condra', 'narcondra': 'Narcondra',
 'hammer_scout': 'Hammer Scout', 'lance_scout': 'Lance Scout', 'bow_scout': 'Bow Scout', 'hero_scout': 'Scout (Hero)',
 'hero_werewolf': 'Werewolf (Hero)', 'balram': 'Balram (Hero)', 'soram': 'Soram (Hero)', 'balgass': 'Balgass',
 'death_spirit': 'Death Spirit (Trainee Soldier)', 'dark_elf': 'Dark Elf', 'sapi_unus': 'Sapi-Unus',
 'sapi_duo': 'Sapi-Duo', 'thunder_napin': 'Thunder Napin', 'shadow_pawn': 'Shadow Pawn',
 'shadow_knight': 'Shadow Knight', 'sapi_tres': 'Sapi-Tres', 'shadow_look': 'Shadow Look',
 'ghost_napin': 'Ghost Napin', 'blaze_napin': 'Blaze Napin', 'sapi_queen': 'Sapi Queen',
 'zombie_fighter': 'Zombie Fighter', 'resurrected_gladiator': 'Resurrected Gladiator',
 'ash_slaughterer': 'Ash Slaughterer', 'burning_lava_giant': 'Burning Lava Giant',
 'ruthless_lava_giant': 'Ruthless Lava Giant', 'ice_walker': 'Ice Walker', 'giant_mammoth': 'Giant Mammoth',
 'ice_giant': 'Ice Giant', 'coolutin': 'Coolutin', 'iron_knight': 'Iron Knight', 'dark_iron_knight': 'Dark Iron Knight'}


class Rows(HTMLParser):
    """Filas de tabla como listas de celdas: [texto, src de la primera imagen]."""
    def __init__(self):
        super().__init__()
        self.rows, self.cell = [], None

    def handle_starttag(self, tag, attrs):
        if tag == 'tr':
            self.rows.append([])
        elif tag in ('td', 'th') and self.rows:
            self.cell = ['', None]
            self.rows[-1].append(self.cell)
        elif tag == 'img' and self.cell is not None and self.cell[1] is None:
            self.cell[1] = dict(attrs).get('src')

    def handle_endtag(self, tag):
        if tag in ('td', 'th'):
            self.cell = None

    def handle_data(self, data):
        if self.cell is not None:
            self.cell[0] += data


page = urllib.request.urlopen(urllib.request.Request(PAGE, headers=UA), timeout=60).read().decode('utf-8', 'replace')
parser = Rows()
parser.feed(page)
urls = {}
for names, images in zip(parser.rows, parser.rows[1:]):
    if any(c[1] for c in names) or not any(c[1] for c in images):
        continue
    for i, cell in enumerate(names):
        name = ' '.join(cell[0].split())
        if name and i < len(images) and images[i][1]:
            urls.setdefault(name, images[i][1])   # la primera aparición de cada nombre

total = 0
missing = []
for target, name in WANT.items():
    url = urls.get(name)
    if url is None:
        missing.append(name); continue
    if url.startswith('//'):
        url = 'https:' + url
    data = urllib.request.urlopen(urllib.request.Request(html.unescape(url), headers=UA), timeout=60).read()
    total += len(data)
    open(os.path.join(RAW, target + '.jpg'), 'wb').write(data)
    img = Image.open(io.BytesIO(data)).convert('RGB')
    w, h = img.size
    side = min(w, h)
    # recorte cuadrado: centrado a lo ancho y un poco por debajo del centro a lo alto (los pies quedan adentro)
    left = (w - side) // 2
    top = min(h - side, int((h - side) * 0.55))
    img.crop((left, top, left + side, top + side)).resize((64, 64), Image.LANCZOS) \
        .quantize(colors=255, method=Image.MEDIANCUT, dither=Image.FLOYDSTEINBERG).save(os.path.join(OUT, target + '.gif'))
    print(f'{target:26} {w}x{h} {len(data):6} bytes')
print('bajadas', len(WANT) - len(missing), 'total', total, 'bytes; faltan', missing)
