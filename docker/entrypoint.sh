#!/bin/sh
set -e

mkdir -p var/cache var/log

if [ "$1" = 'apache2-foreground' ]; then
    php bin/console cache:clear --no-warmup
    php bin/console doctrine:migrations:migrate --no-interaction
    php bin/console cache:warmup

    # var/cache/test also lives in the persisted "var" volume, but it's only ever
    # written by the Admin > Unit tests page running phpunit under APP_ENV=test
    # (see TestReportController), never by this entrypoint's own APP_ENV=prod
    # commands above. Since the container compiles without debug (no automatic
    # resource-freshness check), a stale test-env container from a previous image
    # would otherwise keep being reused forever, silently ignoring newly deployed
    # code (e.g. "Too few arguments" errors after a service's constructor changes).
    php bin/console cache:clear --no-warmup --env=test
    php bin/console cache:warmup --env=test

    chown -R www-data:www-data var translations config/packages/translation.yaml
fi

exec "$@"
