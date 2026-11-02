"""Synthetic-only qualification: release integrity, failure recovery, disabled PHP runtime."""
import argparse
import ast
import copy
from email.message import Message
import importlib.util
import json
import os
import re
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

    def test_packaged_callback_routing_and_private_access(self):
        runtime = self.bundle / 'runtime'
        rules = (runtime / 'admin/allegro/.htaccess').read_text()
        rewrite = re.search(r'<IfModule mod_rewrite\.c>(.*?)</IfModule>', rules, re.S).group(1)
        self.assertIn('RewriteEngine On', rewrite)
        # No redirect, query replacement or QSA: retain the original query implicitly.
        self.assertEqual([line.strip() for line in rewrite.splitlines() if 'callback' in line],
                         ['RewriteRule ^callback/?$ callback.php [L]'])
        self.assertIn('RewriteRule ^allegro/callback/?$ allegro/callback.php [END]',
                      (runtime / 'admin/.htaccess').read_text())
        denied = re.search(r'<FilesMatch "([^"]+)">(.*?)</FilesMatch>', rules, re.S)
        self.assertIn('Require all denied', denied.group(2))
        for name in ['index.php', 'callback.php']:
            self.assertIsNone(re.search(denied.group(1), name), name)
        for name in ['config.php', 'storage.php', 'http.php', 'oauth.php', 'lib.php', 'other.php']:
            self.assertIsNotNone(re.search(denied.group(1), name), name)
        self.assertIn('RewriteRule ^(?:storage|private_allegro|logs|cache)(?:/|$) - [F,END,NC]', rewrite)
        self.assertIn("const ALLEGRO_PROD_CALLBACK = 'https://mgoutlet.pl/admin/allegro/callback';",
                      (runtime / 'admin/allegro/config.php').read_text())
        # Keep both real smoke requests, rather than qualifying only the physical PHP URI.
        tree = ast.parse((ROOT / 'scripts/allegro-infra-smoke.py').read_text())
        paths = next(node.value for node in ast.walk(tree) if isinstance(node, ast.Assign)
                     and any(isinstance(target, ast.Name) and target.id == 'paths' for target in node.targets))
        for path in ['/admin/allegro/callback', '/admin/allegro/callback.php']:
            self.assertTrue(any(isinstance(item, ast.Tuple) and ast.literal_eval(item) == (path, False)
                                for item in paths.elts if isinstance(item, ast.Tuple)))

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
        self.assertEqual(set(jobs), {'qualify', 'build', 'verify', 'inspect', 'diagnose', 'production'})
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

    def test_guard_diagnostics_fixed_categories_without_secret_values(self):
        valid = {'GITHUB_ACTIONS': 'true', 'GITHUB_REPOSITORY': release.REPOSITORY,
                 'GITHUB_REF': 'refs/heads/main', 'ALLEGRO_DEPLOY_CONFIRM': release.CONFIRM,
                 'GH_TOKEN': 'SYNTHETIC_PRIVATE_SENTINEL'}
        response = mock.MagicMock()
        data = {'name': release.ENVIRONMENT, 'can_admins_bypass': False,
                'deployment_branch_policy': {'protected_branches': False, 'custom_branch_policies': True}}
        for cookie in ['', 'SYNTHETIC_PRIVATE_SENTINEL', 'hgo_admin=short', 'hgo_admin=' + 'x' * 32 + '\n']:
            response.__enter__.return_value.read.return_value = json.dumps(data).encode()
            with mock.patch.dict(os.environ, {**valid, 'ALLEGRO_SMOKE_ADMIN_COOKIE': cookie}, clear=True), \
                    mock.patch('urllib.request.build_opener') as opener:
                opener.return_value.open.return_value = response
                with self.assertRaises(release.GuardFailure) as caught:
                    release.environment_guard()
                self.assertEqual(caught.exception.code, 'SMOKE_COOKIE_FORMAT')
                self.assertNotIn('SYNTHETIC_PRIVATE_SENTINEL', str(caught.exception))
        with mock.patch.dict(os.environ, valid, clear=True), mock.patch('urllib.request.build_opener') as opener:
            opener.return_value.open.side_effect = OSError('SYNTHETIC_PRIVATE_SENTINEL')
            with self.assertRaises(release.GuardFailure) as caught:
                release.environment_guard()
            self.assertEqual(caught.exception.code, 'ENV_READ_FAILED')
            self.assertIsNone(caught.exception.__cause__)
        self.assertEqual(release.GuardFailure('SYNTHETIC_PRIVATE_SENTINEL').code, 'UNKNOWN')

    def test_diagnostic_output_is_public_and_guard_only(self):
        import io
        from contextlib import redirect_stdout
        sentinel = 'SYNTHETIC_PRIVATE_SENTINEL'
        self.assertEqual(release.guard_diagnostic(), 'GUARD_DIAGNOSTIC=PASS')
        for code in release.GuardFailure.CODES:
            self.assertEqual(release.guard_diagnostic(release.GuardFailure(code)), 'GUARD_DIAGNOSTIC=' + code)
        self.assertEqual(release.guard_diagnostic(OSError(sentinel)), 'GUARD_DIAGNOSTIC=UNKNOWN')
        with mock.patch.dict(os.environ, {'ALLEGRO_GUARD_DIAGNOSTIC': 'true'}, clear=True), \
                mock.patch.object(sys, 'argv', ['release', 'guard', '--execute']), \
                mock.patch.object(release, 'environment_guard') as guard, \
                mock.patch.object(release, 'FTPS', side_effect=AssertionError('No FTP')), \
                mock.patch.object(release, 'ReadOnlyFTPS', side_effect=AssertionError('No read-only FTP')), \
                mock.patch.object(release, 'verify', side_effect=AssertionError('No release read')), \
                redirect_stdout(io.StringIO()) as output:
            release.main()
            self.assertEqual(output.getvalue(), 'GUARD_DIAGNOSTIC=PASS\n')
            guard.assert_called_once_with(inspect_only=False)
        env = {**os.environ, 'GITHUB_ACTIONS': 'false', 'ALLEGRO_GUARD_DIAGNOSTIC': 'true',
               'ALLEGRO_SMOKE_ADMIN_COOKIE': sentinel, 'GH_TOKEN': sentinel}
        p = subprocess.run([sys.executable, 'scripts/allegro-infra-release.py', 'guard', '--execute'],
                           cwd=ROOT, env=env, capture_output=True, text=True)
        self.assertEqual(p.returncode, 1)
        self.assertEqual(p.stdout.strip(), 'GUARD_DIAGNOSTIC=ACTIONS_IDENTITY')
        self.assertEqual(p.stderr, '')
        self.assertNotIn(sentinel, p.stdout + p.stderr)

    def test_diagnostic_workflow_and_valid_cookie_contract(self):
        import yaml
        w = yaml.load((ROOT / '.github/workflows/deploy-allegro-infra-getspace.yml').read_text(), Loader=yaml.BaseLoader)
        job = w['jobs']['diagnose']
        self.assertEqual(job['if'], "${{ inputs.mode == 'diagnose-guard' }}")
        self.assertEqual(job['environment'], release.ENVIRONMENT)
        self.assertEqual(set(job['env']), {'GH_TOKEN', 'ALLEGRO_SMOKE_ADMIN_COOKIE', 'ALLEGRO_DEPLOY_CONFIRM', 'ALLEGRO_GUARD_DIAGNOSTIC'})
        self.assertEqual([s['uses'] for s in job['steps'] if 'uses' in s], ['actions/checkout@v4', 'actions/setup-python@v5'])
        commands = '\n'.join(s.get('run', '') for s in job['steps'])
        self.assertIn('guard --execute', commands)
        for text in ['ReadOnlyFTPS', 'FTPS(', 'backup ', 'deploy ', 'rollback ', 'smoke.py', 'curl ', 'urllib', 'FTP_', '/me', '/sale']:
            self.assertNotIn(text, commands)
        self.assertEqual(w['jobs']['production']['if'], "${{ inputs.mode == 'deploy' && inputs.confirmation == 'DEPLOY_ALLEGRO_INFRA_OAUTH_OFF' }}")
        valid = {'GITHUB_ACTIONS': 'true', 'GITHUB_REPOSITORY': release.REPOSITORY, 'GITHUB_REF': 'refs/heads/main',
                 'ALLEGRO_DEPLOY_CONFIRM': release.CONFIRM, 'GH_TOKEN': 'synthetic',
                 'ALLEGRO_SMOKE_ADMIN_COOKIE': 'hgo_admin=' + 'x' * 32}
        response = mock.MagicMock()
        response.__enter__.return_value.read.return_value = json.dumps({'name': release.ENVIRONMENT,
            'can_admins_bypass': False, 'deployment_branch_policy': {'protected_branches': False, 'custom_branch_policies': True}}).encode()
        with mock.patch.dict(os.environ, valid, clear=True), mock.patch('urllib.request.build_opener') as opener:
            opener.return_value.open.return_value = response
            release.environment_guard()

    def test_inspect_wire_commands_reject_mutations_and_nonallowlisted_reads(self):
        transport = release.ReadOnlyFTP()
        with mock.patch('ftplib.FTP.putcmd') as send:
            for cmd in ['AUTH TLS', 'USER synthetic', 'PASS synthetic', 'PBSZ 0', 'PROT P',
                        'CWD domains/mgoutlet.pl/public_html', 'TYPE I', 'EPSV', 'PASV',
                        'OPTS MLST type;', 'MLSD admin', 'RETR admin/index.php']:
                transport.putcmd(cmd)
            sent = send.call_count
            for cmd in ['STOR admin/index.php', 'APPE x', 'DELE x', 'MKD x', 'RMD x', 'RNFR x', 'RNTO y',
                        'SITE CHMOD 777 x', 'RETR data/products.json', 'RETR admin/olx/index.php',
                        'RETR ../private_allegro/account.enc', 'USER x\r\nSTOR y']:
                with self.assertRaises(ValueError):
                    transport.putcmd(cmd)
            self.assertEqual(send.call_count, sent)
        self.assertFalse(any(hasattr(release.ReadOnlyFTPS, name) for name in ['write', 'delete', 'ensure_module_dir']))
        self.assertIs(release.ReadOnlyFTPS.protocol(None), release.ReadOnlyFTP)

    def test_inspect_classification_scope_redaction_and_shared_conflicts(self):
        remote = self.remote()
        remote.write = mock.Mock(side_effect=AssertionError('No write'))
        remote.delete = mock.Mock(side_effect=AssertionError('No delete'))
        remote.ensure_module_dir = mock.Mock(side_effect=AssertionError('No mkdir'))
        report = self.base / 'inspect.json'
        rows = release.inspect(self.bundle, report, remote)
        self.assertEqual(len(rows), 11)
        self.assertEqual({r['classification'] for r in rows}, {'missing', 'expected preimage'})
        self.assertEqual({r['path'] for r in rows}, set(release.ALLOWLIST))
        self.assertTrue(all(set(r) == {'path', 'exists', 'size', 'sha256', 'classification'} for r in rows))
        self.assertFalse(any(value in report.read_text() for value in ['CATALOG_SENTINEL', 'OLX_VAULT_SENTINEL']))
        remote.files.update({p: (self.bundle / 'runtime' / p).read_bytes() for p in release.ALLOWLIST})
        rows = release.inspect(self.bundle, self.base / 'target.json', remote)
        self.assertTrue(all(r['classification'] == 'already target' for r in rows))
        for target in ['admin/index.php', 'admin/.htaccess', 'admin/allegro/config.php']:
            with self.subTest(target=target):
                previous = remote.files[target]
                remote.files[target] = b'PRIVATE_CONTENT_NEVER_IN_REPORT'
                path = self.base / (target.replace('/', '-') + '.json')
                with self.assertRaises(ValueError):
                    release.inspect(self.bundle, path, remote)
                self.assertEqual(next(r for r in json.loads(path.read_text()) if r['path'] == target)['classification'], 'conflict')
                self.assertNotIn('PRIVATE_CONTENT', path.read_text())
                remote.files[target] = previous
        del remote.files['admin/index.php']
        with self.assertRaises(ValueError):
            release.inspect(self.bundle, self.base / 'missing-shared.json', remote)
        self.assertTrue(all(path in release.ALLOWLIST for op, path in remote.operations))
        self.assertFalse(self.backup.exists())

    def migration_remote(self):
        # Read Git bytes and apply the old exact OFF transform; never execute historical code.
        commit = '574331c882a51d298d826a81e31ab9c5d1d791b8'
        profile = json.loads(subprocess.check_output(['git', 'show', commit + ':scripts/allegro-off-profile.json'], cwd=ROOT))
        files = {}
        for target in release.ALLOWLIST:
            body = subprocess.check_output(['git', 'show', commit + ':hosting/getspace/' + target], cwd=ROOT)
            text = body.decode('utf-8').replace('\r\n', '\n')
            if target == 'admin/allegro/config.php':
                for before, after in profile['replacements']:
                    self.assertEqual(text.count(before), 1)
                    text = text.replace(before, after)
            files[target] = text.encode()
            self.assertEqual(release.sha(files[target]), release.INSPECT_MIGRATION_PREIMAGES[target])
        return FakeRemote(files)

    def test_migration_inspect_exact_old_new_and_metadata_only(self):
        remote = self.migration_remote()
        rows = release.inspect(self.bundle, self.base / 'old-migration.json', remote, migration=True)
        self.assertEqual(len(rows), 11)
        self.assertEqual(sum(r['classification'] == 'migration_preimage' for r in rows), 8)
        self.assertEqual(sum(r['classification'] == 'already_target' for r in rows), 3)
        self.assertTrue(all(r['exists'] for r in rows))
        for row in rows:
            self.assertEqual(set(row), {'path', 'exists', 'size', 'sha256', 'classification'})
        self.assertTrue(all(op == 'read' and target in release.ALLOWLIST for op, target in remote.operations))
        remote.files = {p: (self.bundle / 'runtime' / p).read_bytes() for p in release.ALLOWLIST}
        rows = release.inspect(self.bundle, self.base / 'new-migration.json', remote, migration=True)
        self.assertTrue(all(r['classification'] == 'already_target' for r in rows))

    def test_migration_inspect_bit_drift_and_missing_fail_every_path(self):
        remote = self.migration_remote()
        for i, target in enumerate(release.ALLOWLIST):
            original = remote.files[target]
            remote.files[target] = bytes([original[0] ^ 1]) + original[1:]
            report = self.base / ('bit-' + str(i) + '.json')
            with self.assertRaises(ValueError):
                release.inspect(self.bundle, report, remote, migration=True)
            row = next(r for r in json.loads(report.read_text()) if r['path'] == target)
            self.assertEqual(row['classification'], 'conflict')
            del remote.files[target]
            report = self.base / ('missing-' + str(i) + '.json')
            with self.assertRaises(ValueError):
                release.inspect(self.bundle, report, remote, migration=True)
            row = next(r for r in json.loads(report.read_text()) if r['path'] == target)
            self.assertFalse(row['exists'])
            self.assertEqual(row['classification'], 'missing')
            remote.files[target] = original
        self.assertTrue(all(op == 'read' for op, path in remote.operations))

    def test_migration_inspect_does_not_authorize_backup_or_deploy(self):
        remote = self.migration_remote()
        with self.assertRaises(ValueError):
            release.backup(self.bundle, self.backup, remote)
        self.assertFalse(self.backup.exists())
        with self.assertRaises(ValueError):
            release.inspect(self.bundle, self.base / 'ordinary.json', remote)
        with self.assertRaises((ValueError, FileNotFoundError)):
            release.deploy(self.bundle, self.backup, remote)
        self.assertTrue(all(op == 'read' for op, path in remote.operations))
        # Verification/recovery remain independent of the one-run migration authorization.
        import inspect as python_inspect
        for function in [release.rollback, release.load_state, release.verify]:
            self.assertNotIn('INSPECT_MIGRATION_PREIMAGES', python_inspect.getsource(function))
        self.assertEqual(set(release.INSPECT_MIGRATION_PREIMAGES), set(release.ALLOWLIST))

    def migration_authorization(self):
        return {'GITHUB_ACTIONS': 'true', 'GITHUB_REPOSITORY': release.REPOSITORY,
                'GITHUB_EVENT_NAME': 'workflow_dispatch', 'GITHUB_REF': 'refs/heads/main',
                'GITHUB_WORKFLOW_REF': release.REPOSITORY + '/.github/workflows/deploy-allegro-infra-getspace.yml@refs/heads/main',
                'GITHUB_JOB': 'production', 'GITHUB_RUN_NUMBER': '16', 'GITHUB_RUN_ATTEMPT': '1',
                'ALLEGRO_DEPLOY_CONFIRM': release.CONFIRM}

    def test_migration_authorization_is_bound_to_one_production_attempt(self):
        valid = self.migration_authorization()
        with mock.patch.dict(os.environ, valid, clear=True):
            self.assertEqual(release.authorized_migration_preimages(), release.INSPECT_MIGRATION_PREIMAGES)
        for key, value in [('GITHUB_RUN_NUMBER', '15'), ('GITHUB_RUN_NUMBER', '17'),
                           ('GITHUB_RUN_ATTEMPT', '2'), ('GITHUB_JOB', 'inspect'),
                           ('GITHUB_EVENT_NAME', 'push'), ('GITHUB_REF', 'refs/heads/other'),
                           ('GITHUB_WORKFLOW_REF', 'other'), ('GITHUB_ACTIONS', 'false'),
                           ('GITHUB_REPOSITORY', 'other/repo'), ('ALLEGRO_DEPLOY_CONFIRM', '')]:
            with mock.patch.dict(os.environ, {**valid, key: value}, clear=True):
                self.assertIsNone(release.authorized_migration_preimages())

    def test_authorized_migration_exact_backup_readback_and_rollback(self):
        remote = self.migration_remote()
        original = dict(remote.files)
        with mock.patch.object(release, 'authorized_migration_preimages', return_value=release.INSPECT_MIGRATION_PREIMAGES):
            state = release.backup(self.bundle, self.backup, remote)
            self.assertEqual(state['before'], release.INSPECT_MIGRATION_PREIMAGES)
            self.assertTrue(all(op == 'read' for op, path in remote.operations))
            release.deploy(self.bundle, self.backup, remote)
            self.assertEqual({path for op, path in remote.operations if op == 'write'}, set(release.ALLOWLIST))
            self.assertEqual(len([op for op, path in remote.operations if op == 'write']), 11)
            for target in release.ALLOWLIST:
                self.assertEqual(remote.files[target], (self.bundle / 'runtime' / target).read_bytes())
        # Recovery remains possible even when run-bound migration authorization is gone.
        release.rollback(self.bundle, self.backup, remote)
        self.assertEqual(remote.files, original)

    def test_migration_rejects_any_difference_from_reviewed_preflight(self):
        remote = self.migration_remote()
        with mock.patch.object(release, 'authorized_migration_preimages', return_value=release.INSPECT_MIGRATION_PREIMAGES):
            for i, target in enumerate(release.ALLOWLIST):
                original = remote.files[target]
                remote.files[target] = original + b'drift'
                with self.assertRaises(ValueError):
                    release.backup(self.bundle, self.base / ('drift-' + str(i)), remote)
                del remote.files[target]
                with self.assertRaises(ValueError):
                    release.backup(self.bundle, self.base / ('absent-' + str(i)), remote)
                remote.files[target] = original
            # Even already-new Device config is NOT the approved production preimage.
            remote.files['admin/allegro/config.php'] = (self.bundle / 'runtime/admin/allegro/config.php').read_bytes()
            with self.assertRaises(ValueError):
                release.backup(self.bundle, self.backup, remote)
        self.assertTrue(all(op == 'read' for op, target in remote.operations))

    def test_migration_backup_cannot_be_reused_by_another_run(self):
        remote = self.migration_remote()
        with mock.patch.object(release, 'authorized_migration_preimages', return_value=release.INSPECT_MIGRATION_PREIMAGES):
            release.backup(self.bundle, self.backup, remote)
        with mock.patch.object(release, 'authorized_migration_preimages', return_value=None):
            with self.assertRaises(ValueError):
                release.deploy(self.bundle, self.backup, remote)
        self.assertTrue(all(op == 'read' for op, target in remote.operations))

    def user_agent_remote(self, previous=True):
        files = {p: (self.bundle / 'runtime' / p).read_bytes() for p in release.ALLOWLIST}
        if previous:
            files['admin/allegro/http.php'] = files['admin/allegro/http.php'].replace(
                b'Home-&-Garden-Outlet-Allegro/1.0.0 (+https://mgoutlet.pl/integracja-allegro/)', b'HGO-Allegro-Stage-B/1')
            self.assertEqual(release.sha(files['admin/allegro/http.php']),
                             '5b7d2d8fdd7130e12a9518f118ad1a540857f58ff57b1ffc68b8ad9300094513')
        return FakeRemote(files)

    def test_user_agent_gate_exact_identity_and_attempt(self):
        valid = {**self.migration_authorization(), 'GITHUB_RUN_NUMBER': '20'}
        approved = {'admin/allegro/http.php': '5b7d2d8fdd7130e12a9518f118ad1a540857f58ff57b1ffc68b8ad9300094513'}
        with mock.patch.dict(os.environ, valid, clear=True):
            self.assertEqual(release.authorized_user_agent_preimages(), approved)
            self.assertIsNone(release.authorized_migration_preimages())
        for key, value in [('GITHUB_RUN_NUMBER', '19'), ('GITHUB_RUN_NUMBER', '21'),
                           ('GITHUB_RUN_NUMBER', '16'), ('GITHUB_RUN_ATTEMPT', '2'),
                           ('GITHUB_JOB', 'inspect'), ('GITHUB_REF', 'refs/heads/other'),
                           ('GITHUB_REPOSITORY', 'other/repo'), ('GITHUB_WORKFLOW_REF', 'other'),
                           ('GITHUB_ACTIONS', 'false'), ('GITHUB_EVENT_NAME', 'push'),
                           ('ALLEGRO_DEPLOY_CONFIRM', ''), ('ALLEGRO_DEPLOY_CONFIRM', 'wrong')]:
            with self.subTest(key=key, value=value), mock.patch.dict(os.environ, {**valid, key: value}, clear=True):
                self.assertIsNone(release.authorized_user_agent_preimages())
        with mock.patch.dict(os.environ, self.migration_authorization(), clear=True):
            self.assertEqual(release.authorized_migration_preimages(), release.INSPECT_MIGRATION_PREIMAGES)
            self.assertIsNone(release.authorized_user_agent_preimages())

    def test_user_agent_backup_deploy_readback_and_unchanged_rollback(self):
        remote = self.user_agent_remote()
        original = dict(remote.files)
        approved = {'admin/allegro/http.php': release.sha(original['admin/allegro/http.php'])}
        with mock.patch.object(release, 'authorized_user_agent_preimages', return_value=approved):
            state = release.backup(self.bundle, self.backup, remote)
            self.assertEqual(state['before'], {p: release.sha(b) for p, b in original.items()})
            self.assertTrue(all(op == 'read' for op, p in remote.operations))
            release.deploy(self.bundle, self.backup, remote)
        self.assertEqual(len([1 for op, p in remote.operations if op == 'write']), 11)
        for p in release.ALLOWLIST:
            self.assertEqual(remote.files[p], (self.bundle / 'runtime' / p).read_bytes())
        release.rollback(self.bundle, self.backup, remote)
        self.assertEqual(remote.files, original)

    def test_user_agent_rejects_every_drift_missing_and_historical_preimage(self):
        remote = self.user_agent_remote()
        approved = {'admin/allegro/http.php': release.sha(remote.files['admin/allegro/http.php'])}
        with mock.patch.object(release, 'authorized_user_agent_preimages', return_value=approved):
            for i, p in enumerate(release.ALLOWLIST):
                original = remote.files[p]
                for label, body in [('drift', original + b'drift'), ('missing', None),
                                    ('historical', self.migration_remote().files[p])]:
                    # Three historical paths are also exact current targets, not alternative preimages.
                    if body is not None and release.sha(body) == release.sha((self.bundle / 'runtime' / p).read_bytes()):
                        continue
                    if body is None:
                        del remote.files[p]
                    else:
                        remote.files[p] = body
                    dest = self.base / (label + str(i))
                    with self.subTest(path=p, case=label), self.assertRaises(ValueError):
                        release.backup(self.bundle, dest, remote)
                    self.assertFalse(dest.exists())
                    remote.files[p] = original
        self.assertTrue(all(op == 'read' for op, p in remote.operations))

    def test_user_agent_backup_reuse_and_historical_gate_cannot_authorize(self):
        remote = self.user_agent_remote()
        approved = {'admin/allegro/http.php': release.sha(remote.files['admin/allegro/http.php'])}
        with mock.patch.object(release, 'authorized_migration_preimages', return_value=release.INSPECT_MIGRATION_PREIMAGES):
            with self.assertRaises(ValueError):
                release.backup(self.bundle, self.backup, remote)
        with mock.patch.object(release, 'authorized_user_agent_preimages', return_value=approved):
            release.backup(self.bundle, self.backup, remote)
        with mock.patch.object(release, 'authorized_user_agent_preimages', return_value=None):
            with self.assertRaises(ValueError):
                release.deploy(self.bundle, self.backup, remote)
        self.assertTrue(all(op == 'read' for op, p in remote.operations))

    def test_user_agent_all_targets_work_without_special_authorization(self):
        remote = self.user_agent_remote(previous=False)
        with mock.patch.object(release, 'authorized_user_agent_preimages', return_value=None):
            release.backup(self.bundle, self.backup, remote)
            release.deploy(self.bundle, self.backup, remote)
        self.assertEqual(len([1 for op, p in remote.operations if op == 'write']), 11)

    def test_user_agent_per_file_drift_and_expired_authorization_block_stor(self):
        for expired in [False, True]:
            remote = self.user_agent_remote()
            p = 'admin/allegro/http.php'
            approved = {p: release.sha(remote.files[p])}
            dest = self.base / str(expired)
            with mock.patch.object(release, 'authorized_user_agent_preimages', return_value=approved) as gate:
                release.backup(self.bundle, dest, remote)
                original_write = remote.write
                def write(target, body):
                    original_write(target, body)
                    if target == release.ALLOWLIST[0]:
                        if expired:
                            gate.return_value = None
                        else:
                            remote.files[p] += b'drift'
                remote.write = write
                with self.assertRaises(ValueError):
                    release.deploy(self.bundle, dest, remote)
                self.assertNotIn(('write', p), remote.operations)
            release.rollback(self.bundle, dest, remote)

    def test_inspect_guard_identity_main_and_cookie_not_required(self):
        valid = {'GITHUB_ACTIONS': 'true', 'GITHUB_REPOSITORY': release.REPOSITORY,
                 'GITHUB_REF': 'refs/heads/main', 'GITHUB_EVENT_NAME': 'workflow_dispatch',
                 'ALLEGRO_OPERATION_MODE': 'inspect', 'GH_TOKEN': 'synthetic',
                 'GITHUB_WORKFLOW_REF': release.REPOSITORY + '/.github/workflows/deploy-allegro-infra-getspace.yml@refs/heads/main'}
        data = {'name': release.ENVIRONMENT, 'can_admins_bypass': False,
                'deployment_branch_policy': {'protected_branches': False, 'custom_branch_policies': True}}
        response = mock.MagicMock()
        response.__enter__.return_value.read.return_value = json.dumps(data).encode()
        with mock.patch.dict(os.environ, valid, clear=True), mock.patch('urllib.request.build_opener') as opener:
            opener.return_value.open.return_value = response
            release.environment_guard(inspect_only=True)
            response.__enter__.return_value.read.return_value = json.dumps({**data, 'name': 'other'}).encode()
            with self.assertRaises(ValueError):
                release.environment_guard(inspect_only=True)
        for key, value in [('GITHUB_REF', 'refs/heads/other'), ('GITHUB_REF', 'refs/tags/main'),
                           ('GITHUB_EVENT_NAME', 'push'), ('ALLEGRO_OPERATION_MODE', 'deploy'),
                           ('GITHUB_WORKFLOW_REF', 'other/workflow'), ('GITHUB_REPOSITORY', 'other/repo')]:
            with mock.patch.dict(os.environ, {**valid, key: value}, clear=True), self.assertRaises(ValueError):
                release.execution_guard(inspect_only=True)
        with mock.patch.dict(os.environ, valid, clear=True), self.assertRaises(ValueError):
            release.execution_guard()  # Inspection identity cannot authorize deployment.

    def test_inspect_workflow_is_separate_from_production(self):
        import yaml
        w = yaml.load((ROOT / '.github/workflows/deploy-allegro-infra-getspace.yml').read_text(), Loader=yaml.BaseLoader)
        self.assertEqual(w['on']['workflow_dispatch']['inputs']['mode']['options'], ['qualify-only', 'inspect', 'diagnose-guard', 'deploy'])
        job = w['jobs']['inspect']
        self.assertEqual(job['if'], "${{ inputs.mode == 'inspect' }}")
        self.assertEqual(job['environment'], release.ENVIRONMENT)
        self.assertEqual(job['needs'], ['qualify', 'build', 'verify'])
        commands = '\n'.join(s.get('run', '') for s in job['steps'])
        self.assertEqual(commands.strip(), 'python scripts/allegro-infra-release.py inspect --report .local-cache/allegro-inspection.json --execute')
        self.assertNotIn('ALLEGRO_SMOKE_ADMIN_COOKIE', str(job))
        self.assertEqual(w['jobs']['production']['if'], "${{ inputs.mode == 'deploy' && inputs.confirmation == 'DEPLOY_ALLEGRO_INFRA_OAUTH_OFF' }}")

    def test_inspect_retr_size_limit(self):
        remote = release.ReadOnlyFTPS.__new__(release.ReadOnlyFTPS)
        remote.ftp = mock.Mock()
        remote.ftp.retrbinary.side_effect = lambda command, callback: callback(b'x' * (1024 * 1024 + 1))
        with self.assertRaises(ValueError):
            remote.read('admin/index.php')
        with self.assertRaises(ValueError):
            remote.read('data/products.json')

    def test_inspect_cli_never_enters_write_or_recovery_actions(self):
        report = self.base / 'cli-inspection.json'
        remote = self.migration_remote()
        remote.close = mock.Mock()
        argv = ['release', 'inspect', '--release', str(self.bundle), '--report', str(report), '--execute']
        with mock.patch.object(sys, 'argv', argv), mock.patch.object(release, 'environment_guard') as guard, \
                mock.patch.object(release, 'ReadOnlyFTPS', return_value=remote), \
                mock.patch.object(release, 'FTPS', side_effect=AssertionError('Writable transport forbidden')), \
                mock.patch.object(release, 'backup', side_effect=AssertionError('Backup forbidden')), \
                mock.patch.object(release, 'deploy', side_effect=AssertionError('Deploy forbidden')), \
                mock.patch.object(release, 'rollback', side_effect=AssertionError('Rollback forbidden')):
            release.main()
            guard.assert_called_once_with(inspect_only=True)
            remote.close.assert_called_once()
        self.assertEqual(len(json.loads(report.read_text())), 11)
        self.assertTrue(all(op == 'read' for op, path in remote.operations))
        with mock.patch.object(sys, 'argv', argv[:-1]), self.assertRaises(ValueError):
            release.main()

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
        for path in ['/admin/allegro/callback', '/admin/allegro/callback.php']:
            self.assertEqual(smoke.evaluate(path, 400, headers, 'Nie można potwierdzić')['status'], 400)
            for status in [302, 404, 500]:
                with self.subTest(path=path, status=status), self.assertRaises(ValueError):
                    smoke.evaluate(path, status, headers, 'PHP error')
        headers['Set-Cookie'] = 'hgo_admin=SESSION_SENTINEL; Secure; HttpOnly; SameSite=Strict'
        headers['Location'] = '/admin/'
        report = smoke.evaluate('/admin/allegro/', 302, headers, '')
        self.assertNotIn('SESSION_SENTINEL', json.dumps(report))
        headers['Content-Security-Policy'] = "default-src 'self'"
        with self.assertRaises(ValueError):
            smoke.evaluate('/admin/allegro/', 302, headers, '')

    def test_device_ready_profile_is_explicit_and_not_deployable(self):
        ready = self.base / 'device-ready'
        manifest = release.build(ready, release.DEVICE_READY_PROFILE)
        self.assertEqual(release.verify(ready)['config_transform'], 'DEVICE_READY_CONFIG_OFF')
        self.assertEqual(manifest['oauth_enabled'], 'private_config')
        self.assertFalse(manifest['sale_api_enabled'])
        self.assertFalse(manifest['callback_logs_redacted'])
        self.assertEqual(len(manifest['files']), 11)
        for target in release.ALLOWLIST:
            if target != 'admin/allegro/config.php':
                self.assertEqual((ready / 'runtime' / target).read_bytes(), (self.bundle / 'runtime' / target).read_bytes())
        config = (ready / 'runtime/admin/allegro/config.php').read_text()
        self.assertNotIn('immutable release gate', config)
        self.assertIn("($private['ALLEGRO_OAUTH_ENABLED'] ?? '0') === '1'", config)
        private_reader = config.split('$readPrivate = static function', 1)[1].split('    };', 1)[0]
        self.assertIn("$value = $private[$key] ?? '';", private_reader)
        self.assertIn("if (!is_string($value)) throw new AllegroSafeException('config');", private_reader)
        self.assertNotIn('getenv', private_reader)
        for key in ['ALLEGRO_CLIENT_ID', 'ALLEGRO_CLIENT_SECRET', 'ALLEGRO_TOKEN_KEY']:
            self.assertEqual(config.count("$readPrivate('" + key + "')"), 1)
            self.assertNotIn("$read('" + key + "')", config)
        self.assertEqual(release.verify(self.bundle)['config_transform'], release.OFF_PROFILE['profile'])
        for action in ['inspect', 'backup', 'deploy', 'rollback']:
            argv = ['release', action, '--release', str(ready), '--execute']
            with mock.patch.object(sys, 'argv', argv), \
                    mock.patch.object(release, 'environment_guard', side_effect=AssertionError('Remote guard forbidden')), \
                    mock.patch.object(release, 'execution_guard', side_effect=AssertionError('Remote guard forbidden')), \
                    mock.patch.object(release, 'FTPS', side_effect=AssertionError('FTPS forbidden')), \
                    mock.patch.object(release, 'ReadOnlyFTPS', side_effect=AssertionError('FTPS forbidden')):
                with self.assertRaisesRegex(ValueError, 'local review only'):
                    release.main()
        changed = copy.deepcopy(manifest)
        changed['oauth_enabled'] = False  # An activatable profile must never masquerade as immutable OFF.
        (ready / release.MANIFEST).write_text(json.dumps(changed))
        with self.assertRaises(ValueError):
            release.verify(ready)

    def test_device_ready_private_config_validation_and_panel(self):
        php = os.environ.get('HGO_TEST_PHP', 'php')
        php_args = json.loads(os.environ.get('HGO_TEST_PHP_ARGS', '[]'))
        ready = self.base / 'ready'
        release.build(ready, release.DEVICE_READY_PROFILE)
        site = ready / 'runtime'
        private = self.base / 'synthetic-private'
        (private / 'storage').mkdir(parents=True)
        (private / 'sessions').mkdir()
        env = {k: v for k, v in os.environ.items() if not k.startswith(('ALLEGRO_', 'HGO_'))}
        env.update({'HGO_ALLEGRO_CONFIG': str(private / 'config.php'),
                    'HGO_STORAGE_DIR': str(private / 'admin-storage'),
                    'HGO_PRIVATE_CONFIG_FILE': str(private / 'absent-paynow.php'),
                    'ALLEGRO_OAUTH_ENABLED': '1'})
        args = [php, *php_args, '-d', 'disable_functions=curl_exec', '-d', 'allow_url_fopen=0',
                '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0',
                '-d', 'session.save_path=' + str(private / 'sessions')]
        result = subprocess.run([*args, str(ROOT / 'tests/allegro-device-ready-tests.php'), str(site), str(private)],
                                env=env, capture_output=True, check=True)
        self.assertIn(b'network calls=0', result.stdout)
        for rel in ['admin/lib.php', 'admin/product-identity.php', 'catalog.php', 'shop-test/config.php',
                    'lib/geoip.php', 'lib/stats-exclusion.php', 'lib/stats-writer.php']:
            dest = site / rel
            dest.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(ROOT / 'hosting/getspace' / rel, dest)
        def quoted(p):
            return "'" + str(p).replace('\\', '/').replace("'", "\\'") + "'"
        script = ("session_name('hgo_admin'); session_start(); $_SESSION['logged_in']=true; session_write_close(); "
                  "require " + quoted(site / 'admin/allegro/index.php') + ';')
        result = subprocess.run([*args, '-r', script], env=env, capture_output=True, check=True)
        self.assertIn('OAuth w konfiguracji</dt><dd>Wyłączony', result.stdout.decode())
        self.assertIn('Nie połączono', result.stdout.decode())
        self.assertNotIn(b'name="action" value="connect"', result.stdout)
        private_values = subprocess.run([*args, '-r', "$a=require " + quoted(private / 'config.php') +
                                        ";$a['ALLEGRO_OAUTH_ENABLED']='1';file_put_contents(" + quoted(private / 'config.php') +
                                        ", '<?php return '.var_export($a,true).';');"],
                                       env=env, capture_output=True, check=True)
        self.assertEqual(private_values.stdout, b'')
        result = subprocess.run([*args, '-r', script], env=env, capture_output=True, check=True)
        self.assertIn('Konfiguracja OAuth</dt><dd>Gotowa', result.stdout.decode())
        self.assertIn(b'name="action" value="connect"', result.stdout)
        self.assertNotIn(b'Fatal error', result.stdout)

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
                    'ALLEGRO_ENVIRONMENT': 'SANDBOX', 'ALLEGRO_AUTH_FLOW': 'DEVICE', 'ALLEGRO_CLIENT_ID': 'synthetic-client-123',
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
            self.assertIn('Device Flow', body)
            for action in ['connect', 'check-device', 'finish', 'check']:
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
