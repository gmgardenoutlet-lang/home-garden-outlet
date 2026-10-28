"""Real full builder on a synthetic site; no network, real products or hosting access."""
import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('release', ROOT / 'scripts/allegro-infra-release.py')
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)
parser = argparse.ArgumentParser()
parser.add_argument('--output', type=Path)
args = parser.parse_args()
owner = tempfile.TemporaryDirectory(prefix='hgo-full-off-') if not args.output else None
base = Path(owner.name) if owner else args.output.resolve()
if not owner:
    base.mkdir(parents=True, exist_ok=False)


def run(command, cwd=ROOT, env=None):
    subprocess.run(command, cwd=cwd, env=env, check=True)


def digest(data):
    return hashlib.sha256(data).hexdigest()


try:
    site = base / 'site'
    site.mkdir()
    text = (ROOT / 'scripts/build-getspace.mjs').read_text(encoding='utf-8')
    # The literal copy lists are authoritative; reject dynamic changes for fresh review.
    for name in ['rootFiles', 'publicDirectories']:
        match = re.search(r'const ' + name + r' = (\[[\s\S]*?\]);', text)
        assert match, 'Review changed builder copy lists'
        for entry in json.loads(match[1]):
            source, target = ROOT / entry, site / entry
            target.parent.mkdir(parents=True, exist_ok=True)
            if source.is_dir():
                shutil.copytree(source, target)
            else:
                shutil.copyfile(source, target)
    for directory in ['hosting/getspace', 'scripts']:
        source, target = ROOT / directory, site / directory
        shutil.copytree(source, target, dirs_exist_ok=True,
                        ignore=shutil.ignore_patterns('storage', 'private_allegro', '.env*', '*.enc', '*.jsonl*',
                                                     'config.private.php', '.credentials.php', '__pycache__'))
    # No production catalogue or installed dependency tree enters the fixture.
    (site / 'vendor').mkdir()
    (site / 'vendor/autoload.php').write_text('<?php // Synthetic build-only dependency placeholder.\n', encoding='utf-8')
    (site / 'data').mkdir()
    for name in ['google-reviews.json', 'shipping-profiles.json']:
        (site / 'data' / name).write_text('{}', encoding='utf-8')
    products = [{'name': 'Synthetic home ' + str(i), 'slug': 'synthetic-home-' + str(i),
                 'category': 'Wyposażenie domu', 'status': 'Dostępne', 'visible': True,
                 'outletPrice': '100 zł', 'image': '/uploads/synthetic-missing.webp'} for i in range(1, 5)]
    products += [{'name': 'Synthetic garden ' + str(i), 'slug': 'synthetic-garden-' + str(i),
                  'category': 'Wyposażenie ogrodu', 'status': 'Dostępne', 'visible': True,
                  'outletPrice': '200 zł', 'image': '/uploads/synthetic-missing.webp'} for i in range(1, 5)]
    snapshot = base / 'synthetic-public.json'
    snapshot.write_text(json.dumps({'products': products}), encoding='utf-8')
    private = site / 'hosting/getspace/admin/allegro'
    sentinel = 'SYNTHETIC_PRIVATE_NEVER_PUBLISH'
    for rel in ['private_allegro/config.php', 'logs/technical.jsonl', 'storage/account.enc',
                'cache/state.json', 'config.private.php', '.env', 'token.enc', 'unreviewed.php']:
        dest = private / rel
        dest.parent.mkdir(parents=True, exist_ok=True)
        dest.write_text(sentinel, encoding='utf-8')
    # The full builder itself runs with complete synthetic enabled configuration.
    vault = base / 'vault'
    vault.mkdir()
    config = base / 'config.php'
    config.write_text('<?php return [];', encoding='utf-8')
    env = {k: v for k, v in os.environ.items() if not k.startswith(('HGO_', 'ALLEGRO_'))}
    env.update({'HGO_PUBLIC_PRODUCTS_SNAPSHOT': str(snapshot), 'HGO_ALLEGRO_CONFIG': str(config),
                'ALLEGRO_OAUTH_ENABLED': '1', 'ALLEGRO_CALLBACK_LOGS_REDACTED': '1',
                'ALLEGRO_ENVIRONMENT': 'SANDBOX', 'ALLEGRO_CLIENT_ID': 'synthetic-client-123',
                'ALLEGRO_CLIENT_SECRET': 'SYNTHETIC_NOT_A_CREDENTIAL',
                'ALLEGRO_TOKEN_KEY': __import__('base64').b64encode(bytes(range(32))).decode(),
                'ALLEGRO_STORAGE_DIR': str(vault),
                'ALLEGRO_REDIRECT_URI': 'https://sandbox.example.test/admin/allegro/callback'})
    run(['node', 'scripts/build-getspace.mjs'], cwd=site, env=env)
    publish = site / 'publish'
    bundle = base / 'dedicated'
    release.build(bundle)
    rows, bodies = release.expected_files()
    hashes = {}
    for target in release.ALLOWLIST:
        full = (publish / target).read_bytes()
        assert full.replace(b'\r\n', b'\n') == bodies[target], target
        if target.startswith('admin/allegro/'):
            assert full == bodies[target], 'Module payload must match byte for byte: ' + target
        hashes[target] = digest(full)
    assert hashes['admin/allegro/config.php'] == 'd1042b53f70713be38edde0158ee656c619a624a2034cbea26f44f138e00bfd9'
    assert {p.name for p in (publish / 'admin/allegro').iterdir()} == {Path(p).name for p in release.ALLOWLIST if p.startswith('admin/allegro/')}
    for path in publish.rglob('*'):
        if path.is_file():
            assert not path.name.startswith('.env') and path.suffix not in ['.enc', '.jsonl', '.lock']
            assert 'private_allegro' not in path.parts and 'vault' not in path.parts
            assert sentinel.encode() not in path.read_bytes(), str(path)
    # Simulate an already deployed dedicated OFF module overwritten by full publish.
    remote = base / 'synthetic-server'
    shutil.copytree(bundle / 'runtime', remote)
    shutil.copytree(publish, remote, dirs_exist_ok=True)
    for target, body in bodies.items():
        assert (remote / target).read_bytes().replace(b'\r\n', b'\n') == body
    for path in (ROOT / 'hosting/getspace/admin/olx').rglob('*'):
        if path.is_file():
            assert (remote / path.relative_to(ROOT / 'hosting/getspace')).read_bytes() == path.read_bytes()
    # Reuse the exact real HTTP/session/CSRF test against files from full publish.
    run([sys.executable, 'tests/allegro-infra-release-tests.py', '--release', str(bundle),
         '--full-artifact', str(publish), 'ReleaseTests.test_packaged_php_oauth_off_even_with_enabled_environment'])
    run(['node', 'tests/allegro-artifact-tests.mjs', '--artifact=' + str(publish)])
    run(['node', 'scripts/prerender-products.mjs'], cwd=site,
        env={**env, 'SITE_ROOT': str(publish)})
    # public-artifact resolves its own script root: run the unchanged test in the fixture.
    (site / 'tests').mkdir()
    shutil.copyfile(ROOT / 'tests/public-artifact-tests.mjs', site / 'tests/public-artifact-tests.mjs')
    run(['node', 'tests/public-artifact-tests.mjs'], cwd=site, env=env)
    # Intentional negative case: a changed activatable source must fail before transfer.
    config_source = site / 'hosting/getspace/admin/allegro/config.php'
    config_source.write_text(config_source.read_text(encoding='utf-8').replace(
        "'enabled' => $read('ALLEGRO_OAUTH_ENABLED', '0') === '1',", "'enabled' => true,"), encoding='utf-8')
    rejected = subprocess.run(['node', 'scripts/build-getspace.mjs'], cwd=site, env=env,
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    assert rejected.returncode != 0 and b'Unreviewed Allegro' in rejected.stderr
    # Preserve the successful artifact as evidence; restore and build once more.
    shutil.copyfile(ROOT / 'hosting/getspace/admin/allegro/config.php', config_source)
    run(['node', 'scripts/build-getspace.mjs'], cwd=site, env=env)
    evidence = {'profile': release.OFF_PROFILE['profile'], 'module_files_equal': 9,
                'all_runtime_equal_after_lf': 11, 'full_payload_sha256': hashes,
                'synthetic_products': len(products), 'env_enabled': True,
                'off_http_pass': True, 'private_sentinels_absent': True, 'server_overlay_pass': True, 'changed_source_rejected': True}
    (base / 'evidence.json').write_text(json.dumps(evidence, indent=2) + '\n', encoding='utf-8')
    print('PASS: full builder OFF, ENV=1, exact module payload, public privacy, server overlay, OLX preserved')
finally:
    if owner:
        owner.cleanup()
