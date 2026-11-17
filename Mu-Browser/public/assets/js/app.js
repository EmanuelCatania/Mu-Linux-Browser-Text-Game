// Pide confirmación antes de enviar formularios o botones marcados con data-confirm (borrar un personaje,
// abandonar una misión). Sin JavaScript funcionan igual, sólo que sin la pregunta.
document.addEventListener('submit', (event) => {
    const message = event.submitter?.dataset.confirm ?? event.target.dataset.confirm;
    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
});

// Fichas de ítems y habilidades: se abren hacia abajo, pero si ahí no entran (ítems cerca del borde de la
// pantalla) se abren hacia arriba, y se corren hacia el costado para no salirse por izquierda o derecha.
const placeTip = (event) => {
    const cell = event.target.closest?.('.tip-cell');
    const tip = cell?.querySelector(':scope > .tip');
    if (!tip) return;

    tip.classList.remove('tip-up');
    tip.style.setProperty('--tip-shift', '0px');
    const margin = 6;
    const view = { width: document.documentElement.clientWidth, height: window.innerHeight };
    let box = tip.getBoundingClientRect();
    if (box.height === 0) return; // todavía oculta

    const anchor = cell.getBoundingClientRect();
    const roomBelow = view.height - anchor.bottom;
    const roomAbove = anchor.top;
    if (box.bottom > view.height - margin && roomAbove > roomBelow) {
        tip.classList.add('tip-up');
        box = tip.getBoundingClientRect();
    }
    if (box.left < margin) {
        tip.style.setProperty('--tip-shift', `${margin - box.left}px`);
    } else if (box.right > view.width - margin) {
        tip.style.setProperty('--tip-shift', `${view.width - margin - box.right}px`);
    }
};
document.addEventListener('mouseover', placeTip);
document.addEventListener('focusin', placeTip);

// Campana de avisos: el contador se refresca solo cada minuto mientras la pestaña está a la vista,
// así se ve un desafío o una venta sin tener que recargar.
(() => {
    const bell = document.querySelector('.notify-bell');
    if (!bell) return;
    const badge = bell.querySelector('.notify-count');
    async function refresh() {
        if (document.hidden) return;
        try {
            const response = await fetch('/notifications/count', { headers: { Accept: 'application/json' } });
            if (!response.ok || !response.headers.get('Content-Type')?.includes('json')) return;
            const { unread } = await response.json();
            badge.textContent = unread;
            badge.hidden = unread < 1;
            bell.classList.toggle('has-unread', unread > 0);
        } catch {
            // sin conexión: se reintenta en la próxima vuelta
        }
    }
    setInterval(refresh, 60000);
    document.addEventListener('visibilitychange', refresh);
})();
