# Arma las tiendas NPC (data/shops/*.xml) a partir del catálogo y de las tablas de drop.
#
# Criterio: las tiendas venden lo básico y lo de nivel bajo; lo demás se consigue cazando.
#   - equipo (armas, armaduras, escudos): sólo lo que también cae de monstruos de nivel 60 o menos
#     (tablas regular1 a regular4);
#   - orbes y pergaminos: sólo los de las tablas skills1 y skills2;
#   - pociones, munición, mascotas básicas y los materiales que no caen de ningún monstruo;
#   - nada de joyas, alas, anillos ni ítems de nivel 380: eso sale de drops, cajas o la Chaos Machine.
# Todo lo que deja de venderse y no tenía otra fuente se agrega a la tabla de drop que le corresponde.
#
# Uso: shops.py   (cwd = Mu-Browser)
import collections, glob, re

NL = chr(10)
ITEM = re.compile('<item[^>]*>([^<]{12,})</item>')
COLS, ROWS = 8, 15

def name_of(code):
    return code.split('.')[-1]

def read(path):
    return [c for c in ITEM.findall(open(path, encoding='utf-8').read()) if c.count('.') > 10]

catalog = {}
for path in sorted(glob.glob('data/items/*.xml')):
    for code in read(path):
        catalog.setdefault(name_of(code), code)
drops = collections.defaultdict(set)
for path in glob.glob('data/drop/*.xml'):
    table = path.replace(chr(92), '/').split('/')[-1][:-4]
    for code in read(path):
        drops[name_of(code)].add(table)
legacy = {}   # códigos tal como estaban en las tiendas heredadas (el modo 'table' los vende así)
for path in sorted(glob.glob('data/shops/*.xml')):
    for code in read(path):
        legacy.setdefault(name_of(code), code)

LOW_GEAR = {'regular1', 'regular1.5', 'regular2', 'regular2.5', 'regular3', 'regular4'}
LOW_SKILLS = {'skills1', 'skills2'}
PIECES = ['helm', 'armor', 'gloves', 'pants', 'boots']

def field(code, i):
    return code.split('.')[i]

def owner(code):
    """Tienda que vende el ítem, o None si no se vende."""
    name, kind, sub, cls = name_of(code), field(code, 0), field(code, 2), field(code, 8)
    if '!' in code or field(code, -2) != '000000' or int(field(code, 3)) > 9000:
        return None
    if kind in '34567':
        if not drops[name] & LOW_GEAR:
            return None
        return {'14': 'lorencia3', '02': 'lorencia3', '16': 'lorencia3', '03': 'noria1', '23': 'noria1', '04': 'elbeland1', '24': 'elbeland1',
                '05': 'lorencia4', '06': 'lorencia4'}.get(cls, 'lorencia2')
    if kind in '12' and sub in '67':
        return 'noria1'                                    # flechas y virotes
    if kind in '129':
        if not drops[name] & LOW_GEAR:
            return None
        if kind == '2' and sub in '89':
            return 'noria2'                                # arcos y ballestas
        if kind == '9':
            return 'noria2' if cls in ('03', '23') else 'devias2'
        if cls in ('04', '24'):
            return 'elbeland1'                             # bastones y libros de Summoner
        if sub == '3':
            return 'lorencia3'                             # bastones
        if cls in ('05', '06'):
            return 'lorencia4'
        if kind == '2':
            return 'devias4' if sub == 'A' else 'devias3'  # lanzas / espadas, hachas y martillos a dos manos
        return 'devias2' if sub == '1' else 'devias3'      # espadas / hachas y mazas de una mano
    if kind == 'G':
        if not drops[name] & LOW_SKILLS and name not in ('orb_of_goblin_summon', 'orb_of_stone_golem_summon', 'orb_of_assasin_summon'):
            return None
        return {'03': 'noria1', '23': 'noria1', '04': 'elbeland2', '24': 'elbeland2', '05': 'lorencia4', '06': 'lorencia4',
                '15': 'devias2', '01': 'devias2', '21': 'devias2'}.get(cls, 'devias1')
    if kind == 'F':
        return 'lorencia1' if name in legacy else None
    if kind == 'C':
        return {'guardian_angel': 'lorencia1', 'imp': 'lorencia1', 'dark_horse': 'lorencia4', 'dark_raven': 'lorencia4'}.get(name)
    if kind == 'I':
        return None if drops[name] else 'lorencia1'        # materiales de evento que no caen
    return None

SHOPS = collections.OrderedDict([
    ('lorencia1', 'Lumen the Barmaid'), ('lorencia2', 'Hanzo the Blacksmith'), ('lorencia3', 'Pasi the Mage'),
    ('lorencia4', 'Harold the Wandering Merchant'), ('noria1', 'Elf Lala'), ('noria2', 'Eo the Craftsman'),
    ('elbeland1', 'Leah'), ('elbeland2', 'Marseille'), ('devias1', 'Isabel the Wizard'),
    ('devias2', 'Zienna the Weapon Merchant'), ('devias3', 'Alex the Wandering Merchant'), ('devias4', 'Thompson Kenel'),
])
stock = collections.OrderedDict((s, []) for s in SHOPS)
for name, code in catalog.items():
    shop = owner(code)
    if shop is not None:
        stock[shop].append(legacy.get(name, code))

# Un set de armadura se vende entero (cuatro piezas o más) o no se vende: nada de piezas sueltas.
for shop in stock:
    count = collections.Counter(name_of(c).rsplit('_', 1)[0] for c in stock[shop] if field(c, 0) in '34567')
    stock[shop] = [c for c in stock[shop] if field(c, 0) not in '34567' or count[name_of(c).rsplit('_', 1)[0]] >= 4]

# Hanzo no tiene lugar para más de cuatro sets en fila: los más fuertes pasan a Harold.
def set_base(code):
    return name_of(code).rsplit('_', 1)[0]

bases = collections.OrderedDict()
for code in stock['lorencia2']:
    if field(code, 0) in '34567':
        bases[set_base(code)] = bases.get(set_base(code), 0) + int(field(code, 3))
for base in sorted(bases, key=bases.get)[4:]:
    moved = [c for c in stock['lorencia2'] if field(c, 0) in '34567' and set_base(c) == base]
    stock['lorencia2'] = [c for c in stock['lorencia2'] if c not in moved]
    stock['lorencia4'] += moved

# --- acomodar cada tienda ---
def size(code):
    return int(field(code, 1)[0]), int(field(code, 1)[1])

class Grid:
    def __init__(self):
        self.cells = ['0'] * (COLS * ROWS)

    def free(self, row, col, w, h):
        return col + w <= COLS and row + h <= ROWS and all(self.cells[r * COLS + c] == '0' for r in range(row, row + h) for c in range(col, col + w))

    def put(self, code, row, col):
        w, h = size(code)
        assert self.free(row, col, w, h), (name_of(code), row, col)
        for r in range(row, row + h):
            for c in range(col, col + w):
                self.cells[r * COLS + c] = '1'
        self.cells[row * COLS + col] = code

    def first_fit(self, code, from_row=0):
        w, h = size(code)
        for row in range(from_row, ROWS):
            for col in range(COLS):
                if self.free(row, col, w, h):
                    self.put(code, row, col)
                    return row
        raise SystemExit('no entra ' + name_of(code))

def arrange(shop, codes):
    grid = Grid()
    kind = lambda c: field(c, 0)
    # pociones y otros 1x1 de consumo: arriba, en fila
    small = [c for c in codes if kind(c) in 'FCI' or (kind(c) in '12' and field(c, 2) in '67')]
    rest = [c for c in codes if c not in small]
    for code in sorted(small, key=lambda c: (kind(c) != 'F', size(c)[1], kind(c), field(c, 2))):
        grid.first_fit(code)
    top = max([i // COLS + 1 for i, v in enumerate(grid.cells) if v != '0'] or [0])
    # sets de armadura: una columna por set, del casco a las botas
    sets = collections.OrderedDict()
    for code in rest:
        if kind(code) in '34567':
            piece = name_of(code).rsplit('_', 1)[-1]
            sets.setdefault(name_of(code)[:-len(piece) - 1], {})[piece] = code
    col = 0
    band = top
    band_height = 0
    for base, pieces in sorted(sets.items(), key=lambda kv: sum(int(field(c, 3)) for c in kv[1].values())):
        width = max(size(c)[0] for c in pieces.values())
        height = sum(size(c)[1] for c in pieces.values())
        if col + width > COLS:
            col, band, band_height = 0, band + band_height, 0
        row = band
        for piece in PIECES:
            if piece in pieces:
                grid.put(pieces[piece], row, col)
                row += size(pieces[piece])[1]
        col += width
        band_height = max(band_height, height)
    # armas y escudos: de las más altas a las más bajas, en el primer hueco; después orbes y pergaminos
    others = [c for c in rest if kind(c) not in '34567']
    gear = [c for c in others if kind(c) != 'G']
    for code in sorted(gear, key=lambda c: (-size(c)[1], kind(c), field(c, 2), int(field(c, 3)))):
        grid.first_fit(code)
    # orbes y pergaminos: en filas propias, debajo de todo lo demás
    below = max([i // COLS + 1 for i, v in enumerate(grid.cells) if v != '0'] or [0]) if gear or sets else top
    for code in sorted([c for c in others if kind(c) == 'G'], key=lambda c: (-size(c)[1], field(c, 2), int(field(c, 6)[1:] or 0))):
        grid.first_fit(code, min(below, ROWS - 2))
    return grid

for shop, npc in SHOPS.items():
    grid = arrange(shop, stock[shop])
    out = ['<?xml version="1.0" encoding="UTF-8"?>', '<shop>']
    for i, cell in enumerate(grid.cells):
        out.append(f'<item id="{i}">{cell}</item>')
        if i % COLS == COLS - 1:
            out.append('')
    town = re.sub('[0-9]', '', shop)
    out += [f'<item>{shop}</item>', f'<item>{town.upper()} {shop[len(town):]}</item>', f'<item>{npc}</item>', '', '</shop>', '']
    open(f'data/shops/{shop}.xml', 'w', encoding='utf-8', newline=NL).write(NL.join(out))
    used = sum(1 for c in grid.cells if c != '0')
    print(f'{shop:10} {npc:30} {len(stock[shop]):3} ítems, {used * 100 // (COLS * ROWS)}% ocupado')

# --- lo que se vendía y ya no: que tenga de dónde salir ---
sold_now = {name_of(c) for codes in stock.values() for c in codes}
CRAFTED = {'satan_wings', 'heaven_wings', 'fairy_wings', 'mystery_wings', 'chaos_dragon_axe', 'strength_fruit', 'agility_fruit', 'stamina_fruit', 'energy_fruit',
           'jewel_of_bless_x_10', 'jewel_of_soul_x_10'}
EXTRA = {'gemstone': ['jewels2'], 'jewel_of_guardian': ['jewels2'], 'wizard_ring': ['rings2'], 'loch_feather': ['parts6', 'parts7'],
         'condor_feather': ['parts7'], 'condor_flame': ['parts7'], 'orb_of_elite_yeti_summon': ['skills3'], 'orb_of_dark_knight_summon': ['skills4'],
         'orb_of_bali_summon': ['skills4'], 'orb_of_soldier_summon': ['skills5']}
added = collections.defaultdict(list)
for name in sorted(set(legacy) - sold_now - CRAFTED):
    if drops[name]:
        continue
    code = catalog.get(name, legacy[name])
    tables = EXTRA.get(name) or (['regular8'] if field(code, 0) in '12345679' else None)
    assert tables, 'sin fuente: ' + name
    for table in tables:
        added[table].append(code)
for table, codes in added.items():
    path = f'data/drop/{table}.xml'
    s = open(path, encoding='utf-8').read()
    i = s.rindex('</drop>')
    s = s[:i].rstrip(NL) + NL + '<!--	antes se vendía en tiendas NPC	-->' + NL + ''.join(f'<item>{c}</item>' + NL for c in codes) + NL + s[i:]
    open(path, 'w', encoding='utf-8', newline=NL).write(s)
    print('drop', table, '+', ', '.join(name_of(c) for c in codes))
print('ya no se venden:', ', '.join(sorted(set(legacy) - sold_now)))
