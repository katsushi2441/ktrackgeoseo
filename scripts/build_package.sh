#!/usr/bin/env bash
# 配布用zipを作る。outputs/ktrackgeoseo-<日付>.zip
set -euo pipefail
cd "$(dirname "$0")/.."
NAME="ktrackgeoseo-$(date +%Y%m%d)"
OUT="outputs/$NAME"
rm -rf "$OUT" "outputs/${NAME}.zip"
mkdir -p "$OUT/public/kseo_data" "$OUT/public/ktg_data" "$OUT/public/ktgs_data" "$OUT/scripts" "$OUT/skills"
for f in ktrackgeoseo.php ktrackgeoseo_aeo.php kseo.php ktrackgeo.php ktrackgeo_dashboard.php ktrackgeo_geo.php \
         ktrackgeoseo_config.php.example kseo_config.php.example ktrackgeo_config.php.example; do
  cp "public/$f" "$OUT/public/$f"
done
for d in kseo_data ktg_data ktgs_data; do cp "public/$d/.htaccess" "$OUT/public/$d/.htaccess"; done
mkdir -p "$OUT/public/ktgs_assets" && cp public/ktgs_assets/* "$OUT/public/ktgs_assets/"
cp scripts/make_password_hash.php "$OUT/scripts/"
cp -r skills/* "$OUT/skills/"
cp AGENTS.md CLAUDE.md INSTALL.md SUPPORT.md README.md LICENSE "$OUT/"
( cd outputs && zip -qr "${NAME}.zip" "$NAME" )
echo "できました: outputs/${NAME}.zip"
unzip -l "outputs/${NAME}.zip" | tail -n +4 | head -40
