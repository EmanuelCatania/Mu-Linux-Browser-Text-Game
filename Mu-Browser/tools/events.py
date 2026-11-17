# Genera los mapas de los eventos con horario (devil_square_1..7 y blood_castle_1..7) a partir de los
# monstruos de los mapas del continente: cada nivel de evento toma los de una franja de niveles.
# Uso: events.py   (cwd = Mu-Browser)
import glob, re

SKIP = re.compile(r'^(golden_|great_golden_)')
BOSSES = {'nightmare', 'selupan', 'medusa', 'lord_of_ferea', 'nix', 'kundun', 'balgass', 'illusion_of_kundun'}
# nivel de evento → franja de niveles de monstruo
BANDS = {1: (10, 35), 2: (30, 55), 3: (50, 75), 4: (70, 95), 5: (90, 110), 6: (105, 130), 7: (125, 160)}

monsters = {}
for path in sorted(glob.glob('data/maps/*.xml')):
    name = path.replace('\\', '/').split('/')[-1][:-4]
    if name.startswith(('kalima_', 'devil_square_', 'blood_castle_')):
        continue
    for mob, body in re.findall(r'<monster name="([^"]+)">(.*?)</monster>', open(path, encoding='utf-8').read(), re.S):
        if SKIP.match(mob) or mob in BOSSES:
            continue
        monsters.setdefault(mob, (int(re.search(r'<level>(\d+)', body).group(1)), body.strip()))

for event, offset in (('devil_square', 0), ('blood_castle', 1)):
    for tier, (low, high) in BANDS.items():
        pool = sorted((lvl, mob) for mob, (lvl, _) in monsters.items() if low <= lvl <= high)
        # Devil Square y Blood Castle alternan monstruos de la misma franja para no ser iguales.
        picked = pool[offset::2] if len(pool) >= 8 else pool
        boss = pool[-1][1]
        picked = [m for _, m in picked if m != boss] or [pool[0][1]]
        out = '<?xml version="1.0" encoding="UTF-8"?>\n\n<map>\n\n<!-- generado por tools/events.py -->\n'
        for mob in picked + [boss]:
            out += f'<monster name="{mob}">\n{monsters[mob][1]}\n</monster>\n\n'
        out += '\n<!-- spots -->\n'
        # del spot 14 al 2, de los monstruos más débiles a los más fuertes; el spot 1 es el jefe
        for i, spot in enumerate(range(14, 1, -1)):
            at = i * len(picked) // 13
            names = {picked[at], picked[min(len(picked) - 1, at + (i % 2))]}
            out += f'<spot id="spot{spot}" x="0" y="0">\n' + ''.join(f'<encounter>{n}</encounter>\n' for n in sorted(names, key=picked.index)) + '</spot>\n\n'
        out += f'<spot id="spot1" x="0" y="0">\n<encounter>{boss}</encounter>\n</spot>\n\n\n</map>\n'
        open(f'data/maps/{event}_{tier}.xml', 'w', encoding='utf-8', newline='\n').write(out)
        print(event, tier, len(picked), boss)
