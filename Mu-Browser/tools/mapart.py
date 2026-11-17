# Arma las imágenes de los mapas extra a partir de los minimapas del cliente (WorldN/mini_map.OZT)
# y reubica los spots sobre las zonas transitables. Uso: mapart.py <Data del cliente>   (cwd = Mu-Browser)
import glob, io, os, re, sys
from PIL import Image, ImageFilter

DATA = sys.argv[1]
WORLDS = {'icarus': 11, 'aida': 34, 'crywolf': 35, 'kanturu': 38, 'kanturu_relics': 39, 'barracks': 42,
          'swamp_of_peace': 57, 'raklion': 58, 'vulcanus': 64}
SIZE = 572


def minimap(n):
    path = [p for p in glob.glob(os.path.join(DATA, '[Ww]orld%d' % n, '*')) if os.path.basename(p).lower() == 'mini_map.ozt'][0]
    img = Image.open(io.BytesIO(open(path, 'rb').read()[4:]))
    img.load()
    return img.convert('RGB')


for map_id, world in WORLDS.items():
    full = minimap(world)
    # recorte cuadrado alrededor de la parte dibujada, para que los mapas chicos no queden perdidos en negro
    mask = full.convert('L').filter(ImageFilter.MedianFilter(9)).point(lambda v: 255 if v > 75 else 0)
    left, top, right, bottom = mask.getbbox()
    side = min(1024, max(right - left, bottom - top) + 80)
    cx, cy = (left + right) // 2, (top + bottom) // 2
    x0 = max(0, min(1024 - side, cx - side // 2))
    y0 = max(0, min(1024 - side, cy - side // 2))
    square = full.crop((x0, y0, x0 + side, y0 + side))
    zone = square.resize((SIZE, SIZE), Image.LANCZOS)
    zone.save(f'public/assets/img/maps/{map_id}.jpg', quality=88)

    # zonas transitables: lo claro, achicado para no caer en un borde
    walk = zone.convert('L').point(lambda v: 255 if v > (38 if map_id == 'kanturu' else 70) else 0).filter(ImageFilter.MinFilter(7))
    px = walk.load()
    points = [(x, y) for y in range(30, SIZE - 30, 4) for x in range(30, SIZE - 60, 4) if px[x, y]]
    if not points:
        walk = zone.convert('L').point(lambda v: 255 if v > 55 else 0)
        px = walk.load()
        points = [(x, y) for y in range(30, SIZE - 30, 4) for x in range(30, SIZE - 60, 4) if px[x, y]]

    xml_path = f'data/maps/{map_id}.xml'
    xml = open(xml_path, encoding='utf-8').read()
    spots = re.findall(r'<spot id="(spot\d+)" x="\d+" y="\d+">', xml)

    # puntos bien separados: se arranca abajo a la izquierda y se agrega siempre el más lejano a los ya elegidos
    chosen = [max(points, key=lambda p: p[1] - p[0])]
    while len(chosen) < len(spots):
        chosen.append(max(points, key=lambda p: min((p[0] - c[0]) ** 2 + (p[1] - c[1]) ** 2 for c in chosen)))
    # recorrido: desde el primero, siempre al más cercano que falte (los spots suben de nivel en ese orden)
    route = [chosen.pop(0)]
    while chosen:
        nxt = min(chosen, key=lambda p: (p[0] - route[-1][0]) ** 2 + (p[1] - route[-1][1]) ** 2)
        chosen.remove(nxt)
        route.append(nxt)

    for spot, (x, y) in zip(spots, route):
        xml = re.sub(r'<spot id="%s" x="\d+" y="\d+">' % spot, f'<spot id="{spot}" x="{x}" y="{y}">', xml)
        # miniatura del spot: la zona alrededor, en la proporción 230x150 de las originales
        scale = side / SIZE
        w, h = 300, 196
        sx = max(0, min(side - w, int(x * scale) - w // 2))
        sy = max(0, min(side - h, int(y * scale) - h // 2))
        square.crop((sx, sy, sx + w, sy + h)).resize((230, 150), Image.LANCZOS).save(f'public/assets/img/maps/{spot}.jpg', quality=88)
    open(xml_path, 'w', encoding='utf-8', newline='\n').write(xml)
    print(map_id, 'world', world, 'recorte', side, route)
