# Monstruos dorados de los mapas extra: datos del Monster.txt oficial e imagen teñida de dorado a partir del monstruo común.
import re, sys
from PIL import Image, ImageOps

table = {}
for line in open(sys.argv[1], encoding='latin-1'):
    m = re.match(r'^(\d+)\s+\d+\s+"([^"]*)"\s+(.*)$', line)
    if m:
        f = m.group(3).split()
        table[int(m.group(1))] = dict(level=f[0], hp=f[1], damage=f[4], defense=f[5], damage_rate=f[7], defense_rate=f[8], official=m.group(2))

# mapa → (dorado, índice oficial, elemento, imagen base para teñir)
GOLDEN = {
    'tarkan': ('golden_tantalos', 82, 'fire', 'tantalos'),
    'icarus': ('golden_crust', 496, 'lightning', 'alpha_crust'),
    'aida': ('golden_stone_golem', 495, 'earth', 'stone_golem'),
    'kanturu': ('golden_satyros', 497, 'lightning', 'satyros'),
    'kanturu_relics': ('golden_twin_tail', 498, 'lightning', 'twin_tale'),
    'swamp_of_peace': ('golden_napin', 500, 'water', 'ghost_napin'),
    'vulcanus': ('great_golden_dragon', 501, 'fire', 'golden_budge_dragon'),
    'raklion': ('golden_iron_knight', 499, 'ice', 'iron_knight'),
}
for map_id, (name, index, element, base) in GOLDEN.items():
    m = table[index]
    block = f'<!-- golden invasion -->\n<monster name="{name}">\n<level>{m["level"]}</level>\n<hp>{m["hp"]}</hp>\n<damage>{m["damage"]}</damage>\n' \
            f'<defense>{m["defense"]}</defense>\n<damage_rate>{m["damage_rate"]}</damage_rate>\n<defense_rate>{m["defense_rate"]}</defense_rate>\n' \
            f'<attribute>{element}</attribute>\n</monster>\n\n'
    path = f'data/maps/{map_id}.xml'
    xml = open(path, encoding='utf-8').read()
    if f'name="{name}"' not in xml:
        at = xml.index('<spot ')
        at = xml.rfind('<!--', 0, at) if '<!--' in xml[max(0, at - 40):at] else at
        xml = xml[:at] + block + xml[at:]
        open(path, 'w', encoding='utf-8', newline='\n').write(xml)

    src = Image.open(f'public/assets/img/monsters/{base}.gif')
    src.seek(0)
    frame = src.convert('RGB')
    if base.startswith('golden_'):
        out = frame                      # ya es dorado: se usa tal cual
    else:
        out = ImageOps.colorize(ImageOps.autocontrast(frame.convert('L')), black=(38, 22, 0), mid=(205, 150, 30), white=(255, 246, 180))
    out.quantize(colors=255).save(f'public/assets/img/monsters/{name}.gif')
    print(f'{map_id:15} {name:20} <- {index} "{m["official"]}" L{m["level"]} hp{m["hp"]}')
