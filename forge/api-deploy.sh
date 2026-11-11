# Forge deploy script — API site (api / *-api.on-forge.com)
# Site type: Laravel · Web directory: /public · Repository: Sprouthubuk/wds-production (main)
# If Forge shows zero-downtime macros ($CREATE_RELEASE / $ACTIVATE_RELEASE), keep them and
# put the commands between them.

cd $FORGE_SITE_PATH

git pull origin $FORGE_SITE_BRANCH

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

( flock -w 10 9 || exit 1
    echo 'Restarting FPM...'; sudo -S service $FORGE_PHP_FPM reload ) 9>/tmp/fpmlock

$FORGE_PHP artisan migrate --force
$FORGE_PHP artisan storage:link 2>/dev/null || true
$FORGE_PHP artisan optimize
$FORGE_PHP artisan queue:restart
