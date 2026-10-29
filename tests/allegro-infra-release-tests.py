"""Synthetic-only qualification: release integrity, failure recovery, disabled PHP runtime."""
import argparse
import copy
from email.message import Message
import importlib.util
import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import unittest
from unittest import mock
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
sys.dont_write_bytecode = True


def module(name, path):
    spec = importlib.util.spec_from_file_location(name, ROOT / path)
    obj = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(obj)
    return obj


release = module('release', 'scripts/allegro-infra-release.py')
smoke = module('smoke', 'scripts/allegro-infra-smoke.py')
parser = argparse.ArgumentParser()
parser.add_argument('--release', type=Path)
parser.add_argument('--full-artifact', type=Path)
ARGS, REST = parser.parse_known_args()


class FakeRemote:
    def __init__(self, files):
        self.files = copy.deepcopy(files)
        self.operations = []
        self.fail = None

    def missing(self, target):
        assert target in release.ALLOWLIST
        return target not in self.files

    def read(self, target):
        assert target in release.ALLOWLIST
        self.operations.append(('read', target))
        return self.files[target]

    def write(self, target, body):
        assert target in release.ALLOWLIST
        self.operations.append(('write', target))
        if self.fail == target:
            self.fail = None
            self.files[target] = body[:17]
            raise OSError('Synthetic interrupted upload')
        self.files[target] = body

    def delete(self, target):
        assert target in release.ALLOWLIST
        self.operations.append(('delete', target))
        del self.files[target]

    def ensure_module_dir(self):
        self.operations.append(('mkdir', 'admin/allegro'))


class ReleaseTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='hgo-allegro-release-')
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.bundle = self.base / 'release'
        if ARGS.release:
            shutil.copytree(ARGS.release, self.bundle)
        else:
            release.build(self.bundle)
        release.verify(self.bundle)
        self.backup = self.base / 'backup'

    def remote(self):
        files = {p: subprocess.check_output(['git', 'show', release.BASE + ':hosting/getspace/' + p], cwd=ROOT)
                 for p in release.SHARED_ADDITIONS}
        files.update({'data/products.json': b'CATALOG_SENTINEL', 'uploads/photo.jpg': b'UPLOAD_SENTINEL',
                      'admin/olx/index.php': b'OLX_CODE_SENTINEL', 'private_olx/account.enc': b'OLX_VAULT_SENTINEL',
                      'private_allegro/config.php': b'ALLEGRO_PRIVATE_SENTINEL', 'shop-test/config.php': b'PAYNOW_SENTINEL'})
        return FakeRemote(files)

    def test_exact_manifest_and_payload(self):
        manifest = release.verify(self.bundle)
        self.assertEqual(len(manifest['files']), 11)
        self.assertFalse(manifest['oauth_enabled'])
        self.assertFalse(manifest['sale_api_enabled'])
        self.assertNotEqual(next(x for x in manifest['files'] if x['target'] == 'admin/allegro/config.php')['source_sha256'],
                            next(x for x in manifest['files'] if x['target'] == 'admin/allegro/config.php')['sha256'])
        self.assertFalse((self.bundle / 'runtime' / release.MANIFEST).exists())
        with self.assertRaises(ValueError):
            release.build(self.bundle)

    def test_unexpected_files_never_pass(self):
        for name in ['private_allegro/config.php', 'admin/allegro/.env', 'admin/allegro/account.enc',
                     'admin/allegro/technical.jsonl', 'admin/allegro/key.key', 'admin/olx/config.php',
                     'data/products.json', 'uploads/example.jpg', 'test.php']:
            with self.subTest(name=name):
                path = self.bundle / 'runtime' / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text('PRIVATE_SENTINEL')
                with self.assertRaises(ValueError):
                    release.verify(self.bundle)
                path.unlink()
        # Even a directory without a secret file must not enter the release.
        (self.bundle / 'runtime/private_allegro').mkdir(exist_ok=True)
        with self.assertRaises(ValueError):
            release.verify(self.bundle)

    def test_tampering_and_manifest_mismatch_fail(self):
        config = self.bundle / 'runtime/admin/allegro/config.php'
        old = config.read_bytes()
        config.write_bytes(old.replace(b"'enabled' => false", b"'enabled' => true"))
        with self.assertRaises(ValueError):
            release.verify(self.bundle)
        config.write_bytes(old)
        path = self.bundle / release.MANIFEST
        manifest = json.loads(path.read_text())
        manifest['files'][0]['target'] = 'admin/olx/config.php'
        path.write_text(json.dumps(manifest))
        with self.assertRaises(ValueError):
            release.verify(self.bundle)

    def test_secret_literal_rejection(self):
        for text in ["<?php return ['ALLEGRO_CLIENT_SECRET' => 'PRIVATE_SENTINEL'];", '-----BEGIN PRIVATE KEY-----',
                     "<?php $access_token = 'PRIVATE_SENTINEL';"]:
            with self.assertRaises(ValueError):
                release.clean_source(text.encode())

    def test_manual_workflow_graph_and_no_production_side_effect_helpers(self):
        import yaml
        text = (ROOT / '.github/workflows/deploy-allegro-infra-getspace.yml').read_text(encoding='utf-8')
        workflow = yaml.load(text, Loader=yaml.BaseLoader)
        self.assertEqual(set(workflow['on']), {'workflow_dispatch'})
        self.assertEqual(workflow['on']['workflow_dispatch']['inputs']['mode']['default'], 'qualify-only')
        jobs = workflow['jobs']
        self.assertEqual(set(jobs), {'qualify', 'build', 'verify', 'production'})
        self.assertEqual(jobs['build']['needs'], 'qualify')
        self.assertEqual(jobs['verify']['needs'], 'build')
        self.assertEqual(set(jobs['production']['needs']), {'qualify', 'build', 'verify'})
        self.assertEqual(jobs['production']['environment'], release.ENVIRONMENT)
        self.assertIn("inputs.mode == 'deploy'", jobs['production']['if'])
        self.assertIn(release.CONFIRM, jobs['production']['if'])
        self.assertEqual(workflow['concurrency']['group'], 'getspace-production')
        for forbidden in ['olx-production-prepare', 'migrate-', 'fetch-public-products', 'build-getspace',
                          'products.json', 'mirror ', 'FTP-Deploy-Action', 'private_olx']:
            self.assertNotIn(forbidden, text)
        for step in jobs['qualify']['steps']:
            self.assertNotIn('secrets.', str(step))

    def test_reconciliation_preserves_current_main_olx(self):
        tracked = subprocess.check_output(['git', 'ls-tree', '-r', '--name-only', release.BASE], cwd=ROOT, text=True).splitlines()
        protected = [p for p in tracked if p.startswith('hosting/getspace/admin/olx/')
                     or (p.startswith('tests/') and 'olx' in Path(p).name)
                     or p in ['.github/workflows/deploy-olx-oauth.yml', 'scripts/deploy-olx-oauth.mjs',
                              'hosting/getspace/admin/lib.php', 'hosting/getspace/admin/product-identity.php']]
        self.assertTrue(protected)
        for source in protected:
            original = subprocess.check_output(['git', 'show', release.BASE + ':' + source], cwd=ROOT)
            self.assertEqual((ROOT / source).read_bytes().replace(b'\r\n', b'\n'), original.replace(b'\r\n', b'\n'), source)
        import yaml
        workflow = yaml.load((ROOT / '.github/workflows/deploy-allegro-infra-getspace.yml').read_text(encoding='utf-8'), Loader=yaml.BaseLoader)
        commands = '\n'.join(s.get('run', '') for s in workflow['jobs']['qualify']['steps'])
        olx_tests = [p for p in protected if p.startswith('tests/')]
        self.assertEqual(len(olx_tests), 6, 'Review any newly added OLX suite before release')
        for source in olx_tests:
            self.assertIn(source, commands, 'QUALIFY must include the entire reviewed OLX regression')

    def test_environment_protection_is_fail_closed(self):
        valid = {'can_admins_bypass': False,
                 'deployment_branch_policy': {'protected_branches': False, 'custom_branch_policies': True},
                 'protection_rules': [{'type': 'required_reviewers', 'prevent_self_review': True, 'reviewers': [{'id': 1}]}]}
        for rules in [[], valid['protection_rules'],
                      [{'type': 'required_reviewers', 'prevent_self_review': False, 'reviewers': [{'id': 1}]}]]:
            release.validate_environment({**valid, 'protection_rules': rules})
        for changed in [{}, {**valid, 'can_admins_bypass': True},
                        {**valid, 'can_admins_bypass': None},
                        {**valid, 'deployment_branch_policy': None},
                        {**valid, 'deployment_branch_policy': {'protected_branches': False, 'custom_branch_policies': False}},
                        {**valid, 'deployment_branch_policy': {'protected_branches': True, 'custom_branch_policies': True}}]:
            with self.assertRaises(ValueError):
                release.validate_environment(changed)

    def test_single_owner_execution_still_requires_main_and_exact_confirmation(self):
        valid = {'GITHUB_ACTIONS': 'true', 'GITHUB_REPOSITORY': release.REPOSITORY,
                 'GITHUB_REF': 'refs/heads/main', 'ALLEGRO_DEPLOY_CONFIRM': release.CONFIRM}
        with mock.patch.dict(os.environ, valid, clear=True):
            release.execution_guard()
        for changed in [{**valid, 'GITHUB_REF': ref} for ref in ['', 'refs/heads/other', 'refs/tags/main']] + [
                {**valid, 'ALLEGRO_DEPLOY_CONFIRM': ''}, {**valid, 'ALLEGRO_DEPLOY_CONFIRM': release.CONFIRM + ' '},
                {**valid, 'GITHUB_ACTIONS': 'false'}, {**valid, 'GITHUB_REPOSITORY': 'other/repo'}]:
            with mock.patch.dict(os.environ, changed, clear=True), self.assertRaises(ValueError):
                release.execution_guard()

    def test_ftps_adapter_tls_allowlist_and_absence_without_network(self):
        class Protocol:
            def __init__(self):
                self.module = False
                self.files = {'admin/index.php': b'CODE', 'admin/.htaccess': b'DENY'}
                self.context = None
                self.protected = False
            def connect(self, host, port):
                assert host == 'ftp.example.test' and port == 21
            def login(self, user, password):
                assert user == 'synthetic-user' and password == 'synthetic-password'
            def prot_p(self):
                self.protected = True
            def cwd(self, root):
                assert root == 'domains/mgoutlet.pl/public_html'
            def mlsd(self, path='', facts=None):
                if not path:
                    return [('admin', {'type': 'dir'})]
                if path == 'admin':
                    return [('index.php', {'type': 'file'}), ('.htaccess', {'type': 'file'})] + ([('allegro', {'type': 'dir'})] if self.module else [])
                assert path == 'admin/allegro'
                if not self.module:
                    raise release.ftplib.error_perm('550 Synthetic absent directory')
                return [(p.rsplit('/', 1)[1], {'type': 'file'}) for p in self.files if p.startswith('admin/allegro/')]
            def mkd(self, path):
                assert path == 'admin/allegro'
                self.module = True
            def storbinary(self, command, stream):
                self.files[command.removeprefix('STOR ')] = stream.read()
            def retrbinary(self, command, receive):
                receive(self.files[command.removeprefix('RETR ')])
            def delete(self, path):
                del self.files[path]
            def close(self):
                pass
        protocol = Protocol()
        def constructor(context, timeout):
            self.assertTrue(context.check_hostname)
            self.assertEqual(context.verify_mode, release.ssl.CERT_REQUIRED)
            self.assertEqual(timeout, 30)
            return protocol
        env = {'ALLEGRO_FTP_SERVER': 'ftp.example.test', 'ALLEGRO_FTP_USERNAME': 'synthetic-user',
               'ALLEGRO_FTP_PASSWORD': 'synthetic-password', 'ALLEGRO_FTP_PUBLIC_DIR': 'domains/mgoutlet.pl/public_html'}
        with mock.patch.dict(os.environ, env), mock.patch.object(release.ftplib, 'FTP_TLS', constructor):
            remote = release.FTPS()
            self.assertTrue(protocol.protected)
            self.assertTrue(remote.missing('admin/allegro/config.php'))
            self.assertFalse(remote.missing('admin/index.php'))
            remote.ensure_module_dir()
            remote.write('admin/allegro/config.php', b'SAFE_CODE')
            self.assertEqual(remote.read('admin/allegro/config.php'), b'SAFE_CODE')
            remote.delete('admin/allegro/config.php')
            for operation in [lambda: remote.read('private_olx/config.php'), lambda: remote.write('data/products.json', b'NO'), lambda: remote.delete('uploads/a.jpg')]:
                with self.assertRaises(ValueError):
                    operation()

    def test_unknown_existing_module_and_missing_shared_file_block(self):
        for change in ['unknown-module', 'missing-shared']:
            remote = self.remote()
            if change == 'unknown-module':
                remote.files['admin/allegro/config.php'] = b'UNKNOWN_EXISTING_CONFIG'
            else:
                del remote.files['admin/index.php']
            with self.assertRaises(ValueError):
                release.backup(self.bundle, self.backup, remote)
            self.assertFalse(self.backup.exists())

    def test_rollback_does_not_overwrite_concurrent_unknown_code(self):
        remote = self.remote()
        release.backup(self.bundle, self.backup, remote)
        release.deploy(self.bundle, self.backup, remote)
        remote.files['admin/index.php'] = b'NEW_EXTERNAL_EDITOR_CHANGE'
        with self.assertRaises(ValueError):
            release.rollback(self.bundle, self.backup, remote)
        self.assertEqual(remote.files['admin/index.php'], b'NEW_EXTERNAL_EDITOR_CHANGE')

    def test_deploy_and_rollback_exact_scope(self):
        remote = self.remote()
        original = copy.deepcopy(remote.files)
        release.backup(self.bundle, self.backup, remote)
        self.assertFalse(any(op[0] == 'write' for op in remote.operations))
        release.deploy(self.bundle, self.backup, remote)
        self.assertEqual({p for op, p in remote.operations if op == 'write'}, set(release.ALLOWLIST))
        release.rollback(self.bundle, self.backup, remote)
        self.assertEqual(remote.files, original)
        self.assertTrue(all(p in release.ALLOWLIST for op, p in remote.operations if op != 'mkdir'))

    def test_interrupted_upload_restores_shared_and_new_code(self):
        remote = self.remote()
        original = copy.deepcopy(remote.files)
        release.backup(self.bundle, self.backup, remote)
        remote.fail = 'admin/index.php'
        with self.assertRaises(OSError):
            release.deploy(self.bundle, self.backup, remote)
        release.rollback(self.bundle, self.backup, remote)
        self.assertEqual(remote.files, original)

    def test_remote_conflicts_before_any_write(self):
        remote = self.remote()
        remote.files['admin/index.php'] += b'UNRELATED_PRODUCTION_CHANGE'
        with self.assertRaises(ValueError):
            release.backup(self.bundle, self.backup, remote)
        self.assertFalse(self.backup.exists())
        self.assertFalse(any(op[0] == 'write' for op in remote.operations))

    def test_concurrent_change_after_backup_blocks_upload(self):
        remote = self.remote()
        release.backup(self.bundle, self.backup, remote)
        remote.files['admin/index.php'] += b'CONCURRENT_CHANGE'
        with self.assertRaises(ValueError):
            release.deploy(self.bundle, self.backup, remote)
        self.assertFalse(any(op[0] == 'write' for op in remote.operations))

    def test_corrupt_backup_blocks_restore(self):
        remote = self.remote()
        release.backup(self.bundle, self.backup, remote)
        (self.backup / 'files/admin/index.php').write_bytes(b'CORRUPTION')
        with self.assertRaises(ValueError):
            release.deploy(self.bundle, self.backup, remote)

    def test_remote_cli_requires_explicit_execution(self):
        for action in ['guard', 'backup', 'deploy', 'rollback']:
            result = subprocess.run([sys.executable, str(ROOT / 'scripts/allegro-infra-release.py'), action], capture_output=True)
            self.assertNotEqual(result.returncode, 0)
        result = subprocess.run([sys.executable, str(ROOT / 'scripts/allegro-infra-smoke.py'), '--report', str(self.base / 'never.json')], capture_output=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse((self.base / 'never.json').exists())

    def test_smoke_response_validation_and_header_redaction(self):
        headers = Message()
        headers['Cache-Control'] = 'no-store, max-age=0'
        headers['Referrer-Policy'] = 'no-referrer'
        headers['Content-Security-Policy'] = "default-src 'none'; frame-ancestors 'none'"
        self.assertEqual(smoke.evaluate('/admin/allegro/callback', 400, headers, 'Nie można potwierdzić')['status'], 400)
        with self.assertRaises(ValueError):
            smoke.evaluate('/admin/allegro/callback', 500, headers, 'PHP error')
        headers['Set-Cookie'] = 'hgo_admin=SESSION_SENTINEL; Secure; HttpOnly; SameSite=Strict'
        headers['Location'] = '/admin/'
        report = smoke.evaluate('/admin/allegro/', 302, headers, '')
        self.assertNotIn('SESSION_SENTINEL', json.dumps(report))
        headers['Content-Security-Policy'] = "default-src 'self'"
        with self.assertRaises(ValueError):
            smoke.evaluate('/admin/allegro/', 302, headers, '')

    def test_packaged_php_oauth_off_even_with_enabled_environment(self):
        php = os.environ.get('HGO_TEST_PHP', 'php')
        php_args = json.loads(os.environ.get('HGO_TEST_PHP_ARGS', '[]'))
        site = self.base / 'site'
        shutil.copytree(self.bundle / 'runtime', site)
        if ARGS.full_artifact:
            for target in release.ALLOWLIST:
                full = (ARGS.full_artifact / target).read_bytes()
                self.assertEqual(full.replace(b'\r\n', b'\n'), (site / target).read_bytes(), target)
                shutil.copyfile(ARGS.full_artifact / target, site / target)
        # Only code dependencies needed by the real admin boot; no production configuration/data.
        for rel in ['admin/lib.php', 'admin/product-identity.php', 'catalog.php', 'shop-test/config.php',
                    'lib/geoip.php', 'lib/stats-exclusion.php', 'lib/stats-writer.php']:
            dest = site / rel
            dest.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(ROOT / 'hosting/getspace' / rel, dest)
        sessions, vault = self.base / 'sessions', self.base / 'vault'
        sessions.mkdir()
        vault.mkdir()
        config_file = self.base / 'config.php'
        env = {k: v for k, v in os.environ.items() if not k.startswith(('ALLEGRO_', 'HGO_'))}
        env.update({'HGO_STORAGE_DIR': str(self.base / 'admin-storage'), 'HGO_PRIVATE_CONFIG_FILE': str(self.base / 'absent-paynow.php'),
                    'HGO_ALLEGRO_CONFIG': str(config_file), 'ALLEGRO_OAUTH_ENABLED': '1', 'ALLEGRO_CALLBACK_LOGS_REDACTED': '1',
                    'ALLEGRO_ENVIRONMENT': 'SANDBOX', 'ALLEGRO_CLIENT_ID': 'synthetic-client-123',
                    'ALLEGRO_CLIENT_SECRET': 'SYNTHETIC_NOT_A_CREDENTIAL', 'ALLEGRO_TOKEN_KEY': __import__('base64').b64encode(secrets.token_bytes(32)).decode(),
                    'ALLEGRO_STORAGE_DIR': str(vault), 'ALLEGRO_REDIRECT_URI': 'https://sandbox.example.test/admin/allegro/callback'})
        # Missing config/key is safe, then a complete synthetic configuration remains OFF.
        router = self.base / 'router.php'
        def php_path(path):
            return "'" + str(path).replace('\\', '/').replace("'", "\\'") + "'"
        router.write_text("""<?php
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if ($path === '/fixture-missing') {
          putenv('ALLEGRO_TOKEN_KEY='); require SITE . '/admin/allegro/index.php'; return;
        }
        if ($path === '/fixture-login') {
          require SITE . '/admin/lib.php'; boot_admin(); $_SESSION['logged_in'] = true;
          header('Content-Type: application/json'); echo json_encode(['csrf'=>csrf_token()]); return;
        }
        if ($path === '/admin/allegro/callback') { require SITE . '/admin/allegro/callback.php'; return; }
        return false;
        """.replace('SITE', php_path(site)), encoding='utf-8')
        sock = socket.socket()
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
        sock.close()
        proc = subprocess.Popen([php, *php_args, '-d', 'disable_functions=curl_exec', '-d', 'allow_url_fopen=0',
                                 '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0', '-d', 'session.save_path=' + str(sessions),
                                 '-S', '127.0.0.1:' + str(port), '-t', str(site), str(router)], env=env,
                                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        self.addCleanup(lambda: (proc.terminate(), proc.wait(timeout=10)))
        opener = urllib.request.build_opener(smoke.NoRedirect())
        def request(path, cookie='', fields=None):
            data = None if fields is None else __import__('urllib.parse', fromlist=['urlencode']).urlencode(fields).encode()
            req = urllib.request.Request(f'http://127.0.0.1:{port}' + path, data=data, headers={'Cookie': cookie})
            try:
                response = opener.open(req, timeout=5)
            except urllib.error.HTTPError as exc:
                response = exc
            with response:
                return response.code, response.headers, response.read().decode('utf-8', 'replace')
        for _ in range(60):
            try:
                anonymous = request('/admin/allegro/')
                break
            except OSError:
                time.sleep(0.05)
        else:
            self.fail('Synthetic PHP server did not start')
        self.assertEqual(anonymous[0], 302)
        status, headers, body = request('/fixture-login')
        self.assertEqual(status, 200)
        cookie = headers['Set-Cookie'].split(';')[0]
        csrf = json.loads(body)['csrf']
        for private_config in [False, True]:
            if private_config:
                config_file.write_text('<?php return [];', encoding='utf-8')
            status, headers, body = request('/admin/allegro/', cookie)
            self.assertEqual(status, 200)
            self.assertIn('OAuth w konfiguracji</dt><dd>Wyłączony', body)
            self.assertNotIn('name="action" value="connect"', body)
            self.assertIn('Niepotwierdzona — BLOCKER PRZED PRODUKCYJNYM OAUTH', body)
            for action in ['connect', 'finish', 'check']:
                result = request('/admin/allegro/', cookie, {'action': action, 'csrf': csrf, 'resume': 'a' * 64})
                self.assertEqual(result[0], 400)
                self.assertIsNone(result[1].get('Location'))
            for path in ['/admin/allegro/callback', '/admin/allegro/callback.php', '/admin/allegro/callback?state=' + 'a' * 64 + '&code=SYNTHETIC_CODE']:
                result = request(path)
                self.assertEqual(result[0], 400)
                self.assertIsNone(result[1].get('Set-Cookie'))
                self.assertNotIn('SYNTHETIC_CODE', result[2])
        harness = self.base / 'forced-flags.php'
        harness.write_text('<?php require ' + php_path(site / 'admin/allegro/lib.php') +
            "; $c=allegro_config(); $c['enabled']=true; $c['logs_safe']=true; " +
            "try { allegro_validate_config($c,true); exit(2); } catch (AllegroSafeException $e) { echo $e->kind; }",
            encoding='utf-8')
        result = subprocess.run([php, *php_args, str(harness)], env=env, capture_output=True, check=True)
        self.assertEqual(result.stdout, b'disabled')
        harness.write_text('<?php require ' + php_path(site / 'admin/allegro/lib.php') +
            "; $c=allegro_config(); $calls=0; foreach (['PROD','SANDBOX'] as $e) { $c['environment']=$e; " +
            "foreach (['GET','POST','PUT','PATCH','DELETE'] as $m) { " +
            "foreach (['/sale/offers','/sale/product-offers','/sale/offer-publication-commands'] as $p) { " +
            "try { allegro_http($c,$m,$p,'synthetic',[],function($r) use (&$calls) {$calls++;return [];}); exit(2); } " +
            "catch (AllegroSafeException $x) { if ($x->kind !== 'policy') exit(3); } } } } echo $calls;",
            encoding='utf-8')
        result = subprocess.run([php, *php_args, str(harness)], env=env, capture_output=True, check=True)
        self.assertEqual(result.stdout, b'0', '/sale rejected before even an injected transport')
        self.assertFalse(any(vault.rglob('*.enc')), 'No token/state/pending vault created')
        self.assertNotIn(env['ALLEGRO_CLIENT_SECRET'], body)
        status, headers, body = request('/fixture-missing', cookie)
        self.assertEqual(status, 400)
        self.assertIn('Konfiguracja Allegro jest niekompletna lub niebezpieczna.', body)
        self.assertNotIn('name="action" value="connect"', body)
        # Test the entirely missing configuration/key on the actual released loader.
        harness = self.base / 'missing.php'
        harness.write_text('<?php require ' + php_path(site / 'admin/allegro/lib.php') + "; putenv('ALLEGRO_TOKEN_KEY='); "
                           "try { allegro_validate_config(allegro_config(), true); exit(2); } catch (AllegroSafeException $e) { echo $e->kind; }", encoding='utf-8')
        result = subprocess.run([php, *php_args, str(harness)], env=env, capture_output=True, check=True)
        self.assertEqual(result.stdout, b'config')


if __name__ == '__main__':
    unittest.main(argv=[sys.argv[0], *REST], verbosity=2)
