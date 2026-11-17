// Inventario, baúl y tiendas: mover ítems arrastrando o con clic (elegir ítem → clic en el destino),
// usarlos, aplicar joyas, comprar y vender. El servidor valida todo; acá sólo se arma el pedido.
(() => {
    const root = document.querySelector('.inv-root');
    if (!root) return;

    const CELL = 32;
    const COLUMNS = 8;
    const csrf = root.dataset.csrf;
    const shop = root.dataset.shop || null;
    const bar = root.querySelector('.inv-actions');
    const label = bar.querySelector('.inv-selected');
    const message = root.querySelector('.inv-message');
    const buttons = Object.fromEntries([...bar.querySelectorAll('button')].map((b) => [b.dataset.action, b]));

    let selected = null; // elemento .grid-item elegido
    let grab = { x: 0, y: 0 }; // dónde se agarró el ítem al arrastrar, en celdas

    const place = (el) => `${el.dataset.container}:${el.dataset.slot}`;

    async function post(url, params) {
        message.hidden = true;
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: new URLSearchParams({ ...params, _csrf: csrf }),
            });
            const data = response.headers.get('Content-Type')?.includes('json') ? await response.json() : null;
            if (data?.ok) {
                window.location.reload();
                return;
            }
            // Sin JSON es casi siempre la sesión vencida: recargar lleva al login.
            if (!data) {
                window.location.reload();
                return;
            }
            message.textContent = data.error;
            message.hidden = false;
        } catch {
            message.textContent = '⚠';
            message.hidden = false;
        }
    }

    function select(el) {
        selected?.classList.remove('selected');
        selected = el;
        for (const button of Object.values(buttons)) button.hidden = true;
        if (!el) {
            label.textContent = label.dataset.empty;
            fillStoreForms(null);
            return;
        }
        el.classList.add('selected');
        label.textContent = el.dataset.name;
        fillStoreForms(el);
        buttons.cancel.hidden = false;

        const container = el.dataset.container;
        if (container === 'inventory' && el.dataset.usable === '1') buttons.use.hidden = false;
        if (buttons.buy && container === 'shop') {
            buttons.buy.textContent = `${buttons.buy.dataset.label} ${el.dataset.price}`;
            buttons.buy.hidden = false;
        }
        if (buttons.sell && (container === 'inventory' || container === 'equipment')) {
            buttons.sell.textContent = `${buttons.sell.dataset.label} ${el.dataset.price}`;
            buttons.sell.hidden = false;
        }
    }

    // Tienda personal: el ítem elegido se manda con los formularios de la página (poner con precio / sacar).
    function fillStoreForms(el) {
        const add = document.getElementById('store-add');
        const remove = document.getElementById('store-remove');
        const container = el?.dataset.container;
        if (add) add.elements.from.value = container === 'inventory' || container === 'equipment' ? place(el) : '';
        if (remove) remove.elements.slot.value = container === 'store' ? el.dataset.slot : '';
    }

    // Mueve `item` al destino; si el destino es otro ítem del inventario y lo que se mueve es una joya, la aplica.
    function moveTo(item, target, slot) {
        // Dentro de la tienda personal los ítems se pueden acomodar; para ponerlos o sacarlos hace falta el formulario
        // (poner pide el precio). La respuesta no es JSON: post() recarga y se ve el resultado.
        if (target === 'store' && item.dataset.container === 'store') {
            post('/store', { do: 'move', slot: item.dataset.slot, to: slot });
            return;
        }
        if (target === 'store' || item.dataset.container === 'store') return;
        if (item.dataset.container === 'shop') {
            if (target === 'inventory') post(`/shops/${shop}/buy`, { slot: item.dataset.slot });
            return;
        }
        if (target === 'shop') {
            post('/shops/sell', { from: place(item) });
            return;
        }
        post('/inventory/move', { from: place(item), to: `${target}:${slot}` });
    }

    function dropOnItem(item, other) {
        const jewel = item.dataset.type === 'D' && item.dataset.container === 'inventory' && other.dataset.container === 'inventory';
        if (!jewel || item === other) return false;
        if (window.confirm(root.dataset.confirmJewel.replace('{jewel}', item.dataset.name).replace('{item}', other.dataset.name))) {
            post('/inventory/jewel', { jewel: item.dataset.slot, target: other.dataset.slot });
        }
        return true;
    }

    // Celda de la grilla bajo el puntero, corrida según por dónde se agarró el ítem.
    function cellAt(grid, event, offset) {
        const box = grid.getBoundingClientRect();
        const col = Math.floor((event.clientX - box.left) / CELL) - offset.x;
        const row = Math.floor((event.clientY - box.top) / CELL) - offset.y;
        const rows = Number(grid.dataset.rows);
        if (col < 0 || row < 0 || col >= COLUMNS || row >= rows) return null;
        return row * COLUMNS + col;
    }

    // --- arrastrar y soltar ---
    root.addEventListener('dragstart', (event) => {
        const item = event.target.closest('.grid-item');
        if (!item) return;
        const box = item.getBoundingClientRect();
        grab = { x: Math.floor((event.clientX - box.left) / CELL), y: Math.floor((event.clientY - box.top) / CELL) };
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', place(item));
        select(item);
        item.classList.add('dragging');
    });
    root.addEventListener('dragend', (event) => event.target.closest('.grid-item')?.classList.remove('dragging'));
    root.addEventListener('dragover', (event) => {
        if (selected && event.target.closest('.grid, .equip-slot')) event.preventDefault();
    });
    root.addEventListener('drop', (event) => {
        if (!selected) return;
        const item = selected;
        const other = event.target.closest('.grid-item');
        const zone = event.target.closest('.grid, .equip-slot');
        if (!zone) return;
        event.preventDefault();
        if (other && dropOnItem(item, other)) return;
        if (zone.classList.contains('equip-slot')) {
            moveTo(item, 'equipment', zone.dataset.slot);
        } else {
            const slot = cellAt(zone, event, grab);
            if (slot !== null) moveTo(item, zone.dataset.container, slot);
        }
    });

    // --- clic: elegir y después tocar el destino ---
    root.addEventListener('click', (event) => {
        const button = event.target.closest('.inv-actions button');
        if (button) {
            if (button.dataset.action === 'cancel') select(null);
            if (button.dataset.action === 'use' && selected) post('/inventory/use', { slot: selected.dataset.slot });
            if (button.dataset.action === 'buy' && selected) post(`/shops/${shop}/buy`, { slot: selected.dataset.slot });
            if (button.dataset.action === 'sell' && selected) post('/shops/sell', { from: place(selected) });
            return;
        }

        const item = event.target.closest('.grid-item');
        if (item) {
            if (selected && selected !== item && dropOnItem(selected, item)) return;
            select(selected === item ? null : item);
            return;
        }

        const zone = event.target.closest('.grid, .equip-slot');
        if (!zone || !selected) return;
        if (zone.classList.contains('equip-slot')) {
            moveTo(selected, 'equipment', zone.dataset.slot);
        } else {
            const slot = cellAt(zone, event, { x: 0, y: 0 });
            if (slot !== null) moveTo(selected, zone.dataset.container, slot);
        }
    });

    // Doble clic: usar (poción, orbe, fruta) o comprar.
    root.addEventListener('dblclick', (event) => {
        const item = event.target.closest('.grid-item');
        if (!item) return;
        if (item.dataset.container === 'shop') post(`/shops/${shop}/buy`, { slot: item.dataset.slot });
        else if (item.dataset.container === 'inventory' && item.dataset.usable === '1') post('/inventory/use', { slot: item.dataset.slot });
    });

    // Teclado: Enter o espacio sobre un ítem lo elige.
    root.addEventListener('keydown', (event) => {
        const item = event.target.closest('.grid-item');
        if (item && (event.key === 'Enter' || event.key === ' ')) {
            event.preventDefault();
            select(selected === item ? null : item);
        }
        if (event.key === 'Escape') select(null);
    });
})();
