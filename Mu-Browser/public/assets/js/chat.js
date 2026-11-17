// Chat: un panel plegable que consulta los mensajes cada pocos segundos mientras está abierto.
// Tras 5 minutos sin escribir deja de consultar, como en el juego original, para no cargar el servidor.
(() => {
    const root = document.querySelector('.chat');
    if (!root) return;

    const POLL_MS = 9000;
    const IDLE_MS = 5 * 60 * 1000;
    const toggle = root.querySelector('.chat-toggle');
    const panel = root.querySelector('.chat-panel');
    const log = root.querySelector('.chat-log');
    const form = root.querySelector('.chat-form');
    const input = form.elements.text;
    const error = root.querySelector('.chat-error');
    const clock = new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' });

    let timer = null;
    let lastActivity = Date.now();

    function render(messages) {
        log.replaceChildren();
        if (!messages.length) {
            const empty = document.createElement('li');
            empty.className = 'gray';
            empty.textContent = root.dataset.empty;
            log.append(empty);
            return;
        }
        for (const message of messages) {
            const line = document.createElement('li');
            if (message.global) line.className = 'blue';
            // textContent: lo que escribió otro jugador nunca se interpreta como HTML
            const who = document.createElement('span');
            who.className = 'chat-class';
            who.textContent = message.class ? ` · ${message.class}` : '';
            line.append(`[${clock.format(new Date(message.time))}] [${message.poster}`, who, `] ${message.text}`);
            log.append(line);
        }
        log.scrollTop = log.scrollHeight;
    }

    async function request(options) {
        try {
            const response = await fetch('/chat', { headers: { Accept: 'application/json' }, ...options });
            if (!response.headers.get('Content-Type')?.includes('json')) return;
            const data = await response.json();
            error.hidden = data.ok;
            if (!data.ok) error.textContent = data.error;
            if (data.messages) render(data.messages);
        } catch {
            // sin conexión: se reintenta en la próxima vuelta
        }
    }

    function schedule() {
        clearTimeout(timer);
        if (panel.hidden || Date.now() - lastActivity > IDLE_MS) return;
        timer = setTimeout(async () => {
            await request({});
            schedule();
        }, POLL_MS);
    }

    function setOpen(open) {
        panel.hidden = !open;
        toggle.setAttribute('aria-expanded', String(open));
        try {
            localStorage.setItem('mu-chat-open', open ? '1' : '0');
        } catch {
            // almacenamiento bloqueado: el chat funciona igual, sólo no recuerda si estaba abierto
        }
        if (open) {
            lastActivity = Date.now();
            request({}).then(schedule);
        } else {
            clearTimeout(timer);
        }
    }

    toggle.addEventListener('click', () => setOpen(panel.hidden));

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const text = input.value.trim();
        if (!text) return;
        input.value = '';
        lastActivity = Date.now();
        await request({ method: 'POST', body: new URLSearchParams({ text, _csrf: root.dataset.csrf }) });
        schedule();
    });

    let wasOpen = false;
    try {
        wasOpen = localStorage.getItem('mu-chat-open') === '1';
    } catch {
        // ver arriba
    }
    if (wasOpen) setOpen(true);
})();
