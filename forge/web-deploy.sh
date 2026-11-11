# Forge deploy script — website (wdscompetitions.co.uk / *.on-forge.com)
# Site type: Static HTML / "Other" · Web directory: /web/public · Same repository and branch.
# Needs the "wds-web" background process (see forge/README.md) and the Nginx config in forge/web-nginx.conf.

cd $FORGE_SITE_PATH

git pull origin $FORGE_SITE_BRANCH

cd web
npm ci --no-audit --no-fund
npm run build

# Restart the Node server so it picks up the new build (Forge's supervisor brings it straight back).
sudo -S supervisorctl restart all 2>/dev/null | grep -i wds || pkill -f "web/dist/server/entry.mjs" || true
