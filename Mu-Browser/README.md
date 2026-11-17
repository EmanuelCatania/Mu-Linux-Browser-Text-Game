# Mu-Linux Browser Text Game — desarrollo

Reescritura sobre PHP 8.4 del juego de navegador de 2011 que está en `../historico/Classic-2011` (referencia de reglas y datos).
Cómo instalarlo y usarlo: ver el [README principal](../README.md).
Stack: Slim 4, Twig, PDO/MySQL 8.4, PHPUnit. Convenciones de Docker tomadas de Mu-Linux-97K.

## Levantar

```bash
docker compose up -d --build
```

No hace falta nada más: queda en http://localhost:8086. Todos los valores tienen uno por defecto, la clave de sesión
se genera sola en el primer arranque y las migraciones de `migrations/` se aplican solas al arrancar `mu-web`.

Para cambiar el puerto, las contraseñas de la base o la zona horaria, copiar `.env.example` a `.env` y editarlo
(conviene hacerlo antes del primer arranque: las contraseñas de MySQL quedan guardadas en su volumen).
Para conectarlo a la red `proxy_net` de un reverse proxy, descomentar `COMPOSE_FILE` en `.env`
(ver `docker-compose.proxy.yml`).

### Primer inicio: la cuenta de administrador

La primera vez el sitio abre la pantalla **Primer inicio** para crear la cuenta administradora. Pide un código de
instalación que el contenedor escribe en su log al arrancar, para que nadie más pueda reclamar el sitio:

```bash
docker compose logs mu-web     # buscar el recuadro "PRIMER INICIO"
```

Después, desde **Admin** (arriba a la derecha) se manejan los ajustes del juego, las publicaciones de la portada,
las cuentas y el foro. Para dar o quitar permisos desde la consola:

```bash
docker compose exec mu-web php bin/admin.php <usuario> [--revoke]
```

## Tests

```bash
docker compose --profile test run --rm --build mu-test
```

Corren sobre SQLite en memoria con `tests/schema.sqlite.sql`, que hay que mantener a la par de `migrations/`.

## Estructura

| Carpeta | Qué tiene |
|---|---|
| `public/` | Único directorio servido: `index.php` (front controller) y `assets/` |
| `src/App.php` | Dependencias, middlewares y rutas |
| `src/Http/` | Controladores, middlewares (sesión, cuenta, idioma, CSRF), vistas |
| `src/Domain/` | Reglas del juego, sin HTTP ni SQL |
| `src/Data/` | Repositorios PDO (siempre consultas preparadas) |
| `templates/` | Plantillas Twig |
| `lang/` | Textos: `es.php` (por defecto), `en.php`, `pt.php` |
| `data/` | Datos del juego: ítems, habilidades, mapas y monstruos (`maps/`), tablas de drop (`drop/`) y reglas ajustables (`game.php`) |
| `migrations/` | Esquema versionado, sólo hacia adelante |

## Idiomas

Toda clave nueva va primero en `lang/es.php` y después en los otros dos; un test falla si algún idioma queda con claves de más o de menos.
El idioma se elige con el selector de la cabecera (cookie `mu_locale`) y se guarda en la cuenta.

## Estado

- [x] Hito 1: esqueleto, Docker, cuentas (registro, login, "recordarme", mi cuenta), idiomas
- [x] Hito 2: personajes (crear, elegir, borrar, puntos de atributo) y catálogos de ítems y habilidades
- [x] Hito 3: mapa interactivo, combate por turnos, experiencia y niveles, drops, Golden Invasion
- [x] Hito 4: inventario y equipo con arrastrar y soltar, joyas, tiendas NPC, baúl, pociones en combate
- [x] Hito 5: Chaos Machine, NPC especiales, cajas, misiones y Kalima
- [x] Hito 6: tienda personal, mercado, clanes, chat, ranking y conectados
- [x] Hito 7: PvP — desafíos, duelo automático, apuestas de zen e ítems y ranking de duelos
- [x] Mapas extra: Icarus, Aida, Kanturu, Kanturu Relics, Karutan, Crywolf, Barracks, Swamp of Peace, Vulcanus, Raklion, Ferea y Nixie
- [x] Barra de batalla, reset al nivel 400, evolución a segunda clase con Sebina the Priestess y dorados en los mapas extra

## Imágenes de los mapas extra

Los fondos de los mapas extra y las miniaturas de sus spots salen de los minimapas del cliente de MU
(`WorldN/mini_map.OZT`); `python tools/mapart.py <carpeta Data del cliente>` los vuelve a generar y reubica los spots.
Las imágenes de sus monstruos son capturas del juego tomadas de https://guides.zigzagmu.ru/p/monster.html
(`python tools/mobs.py <carpeta de descarga> public/assets/img/monsters` las vuelve a bajar y recortar a 64x64).
El fondo de Karutan y todo lo de Ferea y Nixie (fondos, monstruos y estadísticas, que no están en Season 5)
salen de https://infinitygame.rf.gd, otro juego hecho sobre esta misma base.
Para darles arte propio alcanza con dejar los archivos con el nombre esperado; no hay que tocar código:

- `public/assets/img/maps/<mapa>.jpg` (572x572) y `public/assets/img/maps/<spot>.jpg` (230x150), por ejemplo `icarus.jpg` y `spot81.jpg`
- `public/assets/img/monsters/<monstruo>.gif` (64x64), por ejemplo `alquamos.gif`
