#!/bin/bash
set -e

# HostForge (and most container platforms) assign a dynamic port via the
# $PORT environment variable at runtime and expect the app to listen on
# it. We can't bake this into the image at build time since it's only
# known when the container actually starts, so we patch Apache's config
# here, right before starting it.
PORT="${PORT:-80}"

sed -ri "s/^Listen .*/Listen 0.0.0.0:${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/*.conf

exec apache2-foreground