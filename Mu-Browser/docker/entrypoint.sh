#!/bin/sh
# Prepara lo que haga falta y recién después arranca Apache.
set -e

# Clave de sesión: si no vino por el entorno, se genera una al azar la primera vez y se guarda en un volumen,
# así sobrevive a los reinicios y nadie tiene que inventarla.
if [ -z "$SESSION_SECRET" ]; then
    SECRET_FILE=/var/lib/mu-browser/session_secret
    if [ ! -s "$SECRET_FILE" ]; then
        php -r 'echo bin2hex(random_bytes(32));' > "$SECRET_FILE"
        chmod 600 "$SECRET_FILE"
    fi
    SESSION_SECRET=$(cat "$SECRET_FILE")
    export SESSION_SECRET
fi

php /var/www/app/bin/migrate.php
# Primer inicio: si no hay administrador, deja en el log el código para crearlo desde el navegador.
php /var/www/app/bin/setup.php
exec docker-php-entrypoint "$@"
