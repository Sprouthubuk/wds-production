# Forge deploy script — website (production: wdscompetitions.co.uk; staging: staging.wdscompetitions.co.uk)
# Site type: Static HTML / "Other" · Web directory: /web/public · Same repository and branch.
# Needs this site's own Forge background process (forge/README.md: wds-web on 4321, wds-web-staging on 4322),
# started with this site's ABSOLUTE path: node $FORGE_SITE_PATH/web/dist/server/entry.mjs

cd $FORGE_SITE_PATH

git pull origin $FORGE_SITE_BRANCH

cd web
npm ci --no-audit --no-fund
npm run build

# Restart only THIS site's Node server. The match is the exact command line with this site's absolute path
# (pkill -x: the whole line, not a substring), so it can never hit the other site's process. Forge's
# supervisor starts it again straight away with the new build. Never `supervisorctl restart all`: that
# restarts every site's processes and queue workers on the server.
ENTRY="$FORGE_SITE_PATH/web/dist/server/entry.mjs"
if pkill -x -f "node $ENTRY"; then
    echo "Restarted the Node server for $FORGE_SITE_PATH"
else
    echo "WARNING: no Node server running as 'node $ENTRY'. Check this site's background process command (forge/README.md)."
fi
