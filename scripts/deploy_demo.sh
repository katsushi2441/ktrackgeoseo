#!/usr/bin/env bash
# デモの公開。https://proto.exbridge.jp/ktrackgeoseo/
# 設定は demo/ のものを使い、配布物の *.example は触らない。FTPは1接続にまとめる。
set -euo pipefail
cd "$(dirname "$0")/.."
/usr/bin/python3 - <<'PY'
import ftplib, os
for ln in open('/home/kojima/work/aixec/.env', encoding='utf-8'):
    if '=' in ln and not ln.startswith('#'):
        k, v = ln.split('=', 1); os.environ.setdefault(k.strip(), v.strip().strip('"').strip("'"))
f = ftplib.FTP(os.environ['FTP_HOST'], timeout=120); f.login(os.environ['FTP_USER'], os.environ['FTP_PASS'])
root = '/web/proto_exbridge_jp/ktrackgeoseo'
for d in (root, root + '/kseo_data', root + '/ktg_data', root + '/ktgs_data'):
    try: f.mkd(d)
    except ftplib.error_perm: pass
files = {f'public/{n}': n for n in ('ktrackgeoseo.php', 'ktrackgeoseo_aeo.php', 'kseo.php', 'ktrackgeo.php', 'ktrackgeo_dashboard.php', 'ktrackgeo_geo.php')}
files.update({'demo/ktrackgeoseo_config.php': 'ktrackgeoseo_config.php', 'demo/kseo_config.php': 'kseo_config.php',
              'demo/ktrackgeo_config.php': 'ktrackgeo_config.php', 'demo/index.php': 'index.php', 'demo/.htaccess': '.htaccess',
              'demo/ktrackgeoseo-ogp.png': 'ktrackgeoseo-ogp.png', 'demo/llms.txt': 'llms.txt',
              'public/kseo_data/.htaccess': 'kseo_data/.htaccess', 'public/ktg_data/.htaccess': 'ktg_data/.htaccess',
              'public/ktgs_data/.htaccess': 'ktgs_data/.htaccess'})
for src, dst in files.items():
    f.storbinary(f'STOR {root}/{dst}', open(src, 'rb')); print('deployed:', dst)
f.quit()
PY
echo "published: https://proto.exbridge.jp/ktrackgeoseo/"
