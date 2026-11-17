# Capturas del juego para la portada (public/assets/img/shots), con el Chrome instalado en modo headless y el
# protocolo DevTools (cliente websocket mínimo, sin dependencias). Entra con la cuenta de prueba TEST2 del .env.
# Uso: python tools/shots.py   (con el juego andando en localhost:8086)
import base64, http.cookiejar, io, json, os, random, re, socket, struct, subprocess, sys, tempfile, time, urllib.parse, urllib.request
from PIL import Image

ROOT = 'D:/Workspaces/Mu-Linux-Browser-based-text/Mu-Browser'
BASE = 'http://localhost:8086'
OUT = ROOT + '/public/assets/img/shots'
CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe'
PORT = 9333

env = dict(l.split('=', 1) for l in open(ROOT + '/.env', encoding='utf-8').read().splitlines() if '=' in l and not l.startswith('#'))
jar = http.cookiejar.CookieJar()
web = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))

def csrf(path):
    html = web.open(BASE + path).read().decode('utf-8')
    return re.search(r'name="_csrf" value="([^"]+)"', html).group(1)

def post(path, data, page=None):
    data = dict(data, _csrf=csrf(page or path))
    return web.open(BASE + path, urllib.parse.urlencode(data).encode()).geturl()

post('/login', {'username': env['TEST2_USER'], 'password': env['TEST2_PASS']})
assert '/notifications' in web.open(BASE + '/').read().decode('utf-8'), 'no se pudo entrar'

# --- websocket mínimo ---
class WS:
    def __init__(self, url):
        u = urllib.parse.urlparse(url)
        self.s = socket.create_connection((u.hostname, u.port))
        key = base64.b64encode(os.urandom(16)).decode()
        self.s.sendall(f'GET {u.path} HTTP/1.1\r\nHost: {u.hostname}:{u.port}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n'
                       f'Sec-WebSocket-Key: {key}\r\nSec-WebSocket-Version: 13\r\n\r\n'.encode())
        resp = b''
        while b'\r\n\r\n' not in resp:
            resp += self.s.recv(1)
        assert b' 101 ' in resp, resp
        self.n = 0

    def send(self, obj):
        payload = json.dumps(obj).encode()
        head = bytes([0x81])
        n = len(payload)
        head += bytes([0x80 | n]) if n < 126 else (bytes([0x80 | 126]) + struct.pack('>H', n) if n < 65536 else bytes([0x80 | 127]) + struct.pack('>Q', n))
        mask = os.urandom(4)
        self.s.sendall(head + mask + bytes(b ^ mask[i % 4] for i, b in enumerate(payload)))

    def recv_exact(self, n):
        data = b''
        while len(data) < n:
            chunk = self.s.recv(n - len(data))
            if not chunk:
                raise EOFError
            data += chunk
        return data

    def recv(self):
        msg = b''
        while True:
            b1, b2 = self.recv_exact(2)
            n = b2 & 0x7F
            if n == 126:
                n = struct.unpack('>H', self.recv_exact(2))[0]
            elif n == 127:
                n = struct.unpack('>Q', self.recv_exact(8))[0]
            msg += self.recv_exact(n)
            if b1 & 0x80:
                return json.loads(msg)

    def call(self, method, **params):
        self.n += 1
        self.send({'id': self.n, 'method': method, 'params': params})
        while True:
            m = self.recv()
            if m.get('id') == self.n:
                return m.get('result', {})

profile = tempfile.mkdtemp()
chrome = subprocess.Popen([CHROME, '--headless=new', f'--remote-debugging-port={PORT}', f'--user-data-dir={profile}', '--window-size=1100,760',
                           '--hide-scrollbars', '--no-first-run', '--no-default-browser-check', 'about:blank'])
try:
    for _ in range(50):
        try:
            targets = json.load(urllib.request.urlopen(f'http://127.0.0.1:{PORT}/json/list'))
            break
        except OSError:
            time.sleep(0.2)
    page = next(t for t in targets if t['type'] == 'page')
    ws = WS(page['webSocketDebuggerUrl'])
    ws.call('Emulation.setDeviceMetricsOverride', width=1100, height=760, deviceScaleFactor=1, mobile=False)
    for c in jar:
        ws.call('Network.setCookie', name=c.name, value=c.value, domain='localhost', path=c.path or '/')

    def shot(path, n, crop=(0, 0, 1100, 760)):
        ws.call('Page.navigate', url=BASE + path)
        time.sleep(2.5)
        ws.call('Runtime.evaluate', expression="document.querySelectorAll('.ticker-track').forEach(t => t.style.animation = 'none'); document.querySelector('.chat-panel')?.setAttribute('hidden', '')")
        time.sleep(0.3)
        data = base64.b64decode(ws.call('Page.captureScreenshot', format='png')['data'])
        im = Image.open(io.BytesIO(data)).convert('RGB').crop(crop)
        im.save(f'{OUT}/{n}.jpg', quality=88)
        thumb = im.copy()
        thumb.thumbnail((200, 145), Image.LANCZOS)
        canvas = Image.new('RGB', (200, 145), (255, 255, 255))
        canvas.paste(thumb, ((200 - thumb.width) // 2, (145 - thumb.height) // 2))
        canvas.save(f'{OUT}/{n}t.jpg', quality=88)
        print(n, path, im.size)

    os.makedirs(OUT, exist_ok=True)
    shot('/map', 1)
    # una batalla en Lorencia para la captura, y después se sale
    post('/battle/enter', {'map': 'lorencia', 'spot': 'spot1'}, '/map/lorencia?spot=spot1')
    shot('/battle', 2)
    post('/battle/leave', {}, '/battle')
    shot('/inventory', 3)
    shot('/shops/lorencia2', 4)
    ws.call('Browser.close')
finally:
    time.sleep(1)
    chrome.kill()
