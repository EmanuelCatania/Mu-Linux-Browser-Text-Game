# Karutan (fondo), Ferea y Nixie (mapas nuevos) a partir de lo bajado de infinitygame.rf.gd.
# Uso: maps11.py <carpeta con lo bajado>   (cwd = Mu-Browser)
import os, re, shutil, sys
from PIL import Image, ImageFilter

RAW = sys.argv[1]
SIZE = 572

# Estadísticas tal cual las usa infinitygame.rf.gd (estos mapas no están en el Monster.txt de Season 5).
NEW = {
 'ferea': (18, 'ferea.xml', {'ferea_knight': 'Ferea_Knight[Lv186]', 'ferea_archer': 'Ferea_Archer[Lv188]',
                             'ferea_fighter': 'Ferea_Fighter[Lv190]', 'ferea_general': 'Ferea_General[Lv192]'},
           [['ferea_knight'], ['ferea_knight', 'ferea_archer'], ['ferea_archer', 'ferea_fighter'],
            ['ferea_fighter', 'ferea_general'], ['ferea_general']]),
 'nixie': (19, 'nixie.xml', {'vasuki': 'Vasuki[Lv194]', 'bass': 'Bass[Lv196]', 'paraca': 'Paraca[Lv198]'},
           [['vasuki'], ['vasuki', 'bass'], ['bass', 'paraca'], ['paraca']]),
}

for map_id, (number, source, names, spots) in NEW.items():
    src = open(os.path.join(RAW, source), encoding='latin-1').read()
    out = '<?xml version="1.0" encoding="UTF-8"?>\n\n<map>\n\n<!-- monster data: valores de infinitygame.rf.gd (mapa posterior a Season 5) -->\n'
    for name, theirs in names.items():
        block = re.search(r'<monster name="%s">(.*?)</monster>' % re.escape(theirs), src, re.S).group(1)
        get = lambda tag: re.search(r'<%s>([^<]*)</%s>' % (tag, tag), block).group(1).strip()
        out += f'<monster name="{name}">\n'
        for tag in ['level', 'hp', 'damage', 'defense', 'damage_rate', 'defense_rate', 'attribute']:
            out += f'<{tag}>{get(tag)}</{tag}>\n'
        out += '</monster>\n\n'
        shutil.copyfile(os.path.join(RAW, name + '.gif'), f'public/assets/img/monsters/{name}.gif')
    out += '\n<!-- spots -->\n'
    for i, common in enumerate(spots, 1):
        out += f'<spot id="spot{number}{i}" x="0" y="0">\n' + ''.join(f'<encounter>{n}</encounter>\n' for n in common) + '</spot>\n\n'
    open(f'data/maps/{map_id}.xml', 'w', encoding='utf-8', newline='\n').write(out + '\n</map>\n')

# fondo, ubicación de spots sobre lo transitable y miniaturas (misma idea que tools/mapart.py)
for map_id in ['karutan', 'ferea', 'nixie']:
    zone = Image.open(os.path.join(RAW, map_id + '.jpg')).convert('RGB')
    zone.save(f'public/assets/img/maps/{map_id}.jpg', quality=90)
    walk = zone.convert('L').point(lambda v: 255 if v > 60 else 0).filter(ImageFilter.MinFilter(9))
    px = walk.load()
    points = [(x, y) for y in range(30, SIZE - 30, 4) for x in range(30, SIZE - 60, 4) if px[x, y]]
    path = f'data/maps/{map_id}.xml'
    xml = open(path, encoding='utf-8').read()
    spots = re.findall(r'<spot id="(spot\d+)" x="\d+" y="\d+">', xml)
    chosen = [max(points, key=lambda p: p[1] - p[0])]
    while len(chosen) < len(spots):
        chosen.append(max(points, key=lambda p: min((p[0] - c[0]) ** 2 + (p[1] - c[1]) ** 2 for c in chosen)))
    route = [chosen.pop(0)]
    while chosen:
        nxt = min(chosen, key=lambda p: (p[0] - route[-1][0]) ** 2 + (p[1] - route[-1][1]) ** 2)
        chosen.remove(nxt)
        route.append(nxt)
    for spot, (x, y) in zip(spots, route):
        xml = re.sub(r'<spot id="%s" x="\d+" y="\d+">' % spot, f'<spot id="{spot}" x="{x}" y="{y}">', xml)
        w, h = 184, 120
        sx = max(0, min(SIZE - w, x - w // 2))
        sy = max(0, min(SIZE - h, y - h // 2))
        zone.crop((sx, sy, sx + w, sy + h)).resize((230, 150), Image.LANCZOS).save(f'public/assets/img/maps/{spot}.jpg', quality=88)
    open(path, 'w', encoding='utf-8', newline='\n').write(xml)
    print(map_id, route)


def sub(p, pairs):
    s = open(p, encoding='utf-8').read()
    for a, b in pairs:
        if a not in s:
            print('MISSING in', p, ':', a[:70]); continue
        s = s.replace(a, b)
    open(p, 'w', encoding='utf-8', newline='\n').write(s)


sub('src/Domain/World/MapCatalog.php', [("        'raklion' => [150, 202],\n", "        'raklion' => [150, 202],\n        'ferea' => [62, 292],\n        'nixie' => [200, 404],\n")])
sub('src/Http/Controller/MapController.php', [("'swamp_of_peace' => 'down', 'aida' => 'down',", "'swamp_of_peace' => 'down', 'aida' => 'down', 'nixie' => 'down',")])
sub('tests/Domain/WorldTest.php', [("self::assertCount(18, $maps->all());", "self::assertCount(20, $maps->all());")])
sub('README.md', [
 ("Mapas extra: Icarus, Aida, Kanturu, Kanturu Relics, Karutan, Crywolf, Barracks, Swamp of Peace, Vulcanus y Raklion",
  "Mapas extra: Icarus, Aida, Kanturu, Kanturu Relics, Karutan, Crywolf, Barracks, Swamp of Peace, Vulcanus, Raklion, Ferea y Nixie"),
 ("Sólo el fondo de Karutan (no está en el cliente de Season 5) sigue con imagen de reserva.",
  "El fondo de Karutan y todo lo de Ferea y Nixie (fondos, monstruos y estadísticas, que no están en Season 5)\nsalen de https://infinitygame.rf.gd, otro juego hecho sobre esta misma base."),
])
