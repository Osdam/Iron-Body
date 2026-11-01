#!/bin/bash
# Copias del banco de deploy-h6-backend-fase1.sh y h6-integrations-scope.sh en $B/root. Solo cambian rutas, psql, la
# URL, los roles (iron → h5_owner, iron_app → h5_app), los dueños esperados (root/www-data → el usuario del Mac), el
# canal del log (production → local) y la comprobación de root. La LÓGICA es la del repo: el diff se enseña al final.
# Uso: bash preparar.sh <banco>       (se repite cada vez que cambian los scripts del repo)
. "$(dirname "$0")/comun.sh"
B=$(banco_dir "${1:-}")
cargar_banco "$B"
ME=$(whoami)
URL="http://127.0.0.1:$BANCO_PORT/api"
BPSQL="psql -X -Atq -v ON_ERROR_STOP=1 -h $PG_HOST -p $PG_PORT -U $PG_USER -d $BANCO_DB"
comun() {
  sed -e "s#/var/www/api#$B/api#g" -e "s#https://api.ironbodyneiva.cloud/api#$URL#g" \
      -e "s#sudo -u postgres psql -X -Atq -v ON_ERROR_STOP=1 -d ironbody#$BPSQL#g" \
      -e "s#\[ \"\$(id -u)\" = 0 \]#true#" -e "s#/root/#$B/root/#g" \
      -e "s#= www-data \]#= $ME ]#g" -e "s#production\\.ERROR#local.ERROR#g" "$1"
}
comun "$OPS/deploy-h6-backend-fase1.sh" | sed -e "s#/usr/local/bin/ironbody-migrate#$B/bin/ironbody-migrate#g" \
  -e "s#'iron_app'#'h5_app'#g" -e "s#has_schema_privilege('iron'#has_schema_privilege('h5_owner'#g" \
  -e "s#30 27 2 11 iron iron iron true 0#30 27 2 11 h5_owner h5_owner h5_owner true 0#g" \
  -e "s#\" iron iron iron true \"#\" h5_owner h5_owner h5_owner true \"#g" \
  -e "s#iron,iron,iron#h5_owner,h5_owner,h5_owner#g" -e "s#= \"root 600\"#= \"$ME 600\"#g" > "$B/root/deploy-h6-backend-fase1.sh"
comun "$OPS/h6-integrations-scope.sh" | sed -e "s#install -m 600 -o root -g root#install -m 600#g" > "$B/root/h6-integrations-scope.sh"
chmod 700 "$B/root/"*.sh
for f in deploy-h6-backend-fase1.sh h6-integrations-scope.sh; do
  bash -n "$B/root/$f"
  echo "== $f: $(diff "$OPS/$f" "$B/root/$f" | grep -c '^>' || true) líneas con sustituciones del banco (diff \"$OPS/$f\" \"$B/root/$f\")"
done
