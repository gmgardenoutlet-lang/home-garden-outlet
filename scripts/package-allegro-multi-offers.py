"""Build an offline DirectAdmin patch and a data-preserving pause rollback. No network."""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parents[1]
BASE = 'f678c31bab176e97fe56e591079041c1fcd1fe34'
FILES = ('admin/allegro/storage.php', 'admin/allegro/oauth.php',
         'admin/allegro/lib.php', 'admin/marketplace-status.php', 'admin/allegro/index.php')
PAUSE = b"""<?php
declare(strict_types=1);
require __DIR__ . '/../lib.php';
boot_admin();
require_login();
http_response_code(503);
header('Cache-Control: no-store');
header('Content-Type: text/plain; charset=utf-8');
echo 'Allegro: przerwa techniczna. Zapisane oferty i polaczenie pozostaja zachowane.';
exit;
"""


def build(output):
    output = Path(output).resolve()
    output.mkdir(parents=True, exist_ok=False)
    entries = {}
    manifest = {'base': BASE, 'source': subprocess.check_output(
        ['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip(), 'files': []}
    for target in FILES:
        source = 'hosting/getspace/' + target
        body = (ROOT / source).read_bytes()
        previous = subprocess.check_output(['git', 'show', BASE + ':' + source], cwd=ROOT)
        entries['upload/' + target] = body
        entries['reference-original-do-not-upload/' + target] = previous
        manifest['files'].append({'source': source, 'target': 'domains/mgoutlet.pl/public_html/' + target,
                                  'sha256': hashlib.sha256(body).hexdigest(),
                                  'base_sha256': hashlib.sha256(previous).hexdigest()})
    entries['rollback-pause/admin/allegro/index.php'] = PAUSE
    entries['INSTRUKCJA.md'] = (ROOT / 'documents/allegro-multi-offers-directadmin.md').read_bytes()
    entries['manifest.json'] = (json.dumps(manifest, ensure_ascii=False, indent=2) + '\n').encode()
    archive = output / 'allegro-multi-offers-directadmin.zip'
    with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as bundle:
        for name, body in entries.items():
            bundle.writestr(name, body)
            dest = output / name
            dest.parent.mkdir(parents=True, exist_ok=True)
            dest.write_bytes(body)
    with zipfile.ZipFile(archive) as bundle:
        assert bundle.testzip() is None
        for name, body in entries.items():
            assert bundle.read(name) == body
    print(archive)
    print('SHA256 ' + hashlib.sha256(archive.read_bytes()).hexdigest())


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', required=True)
    build(parser.parse_args().output)
