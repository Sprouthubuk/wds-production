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

# Verity's settings and code check: shown in Forge's deploy log and kept in storage/logs. It never stops
# the deploy (a FAIL here is a settings problem to fix, not a reason to leave the old code running);
# the daily wds:verity-check-alert emails VERITY_ALERT_EMAILS while any row is FAIL.
mkdir -p storage/logs
{ echo "=== verity:check $(date -u +%Y-%m-%dT%H:%M:%SZ)"; $FORGE_PHP artisan verity:check 2>&1; echo "exit $?"; } | tee -a storage/logs/verity-check-deploy.log || true
$FORGE_PHP artisan storage:link 2>/dev/null || true
$FORGE_PHP artisan optimize
$FORGE_PHP artisan queue:restart
