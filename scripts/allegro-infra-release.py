"""Allegro-only release. No network is used by build/verify or by importing this module."""
import argparse
import datetime as dt
import ftplib
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import re
import ssl
import subprocess
import sys
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
BASE = 'df4c32cc77f2500a5ba238d892803f9afac1f7bd'
# Reviewed B.1 module, LF-normalized in ALLOWLIST order. Any source change,
# including a hidden credential or a widened HTTP policy, requires fresh review.
REVIEWED_MODULE_SHA256 = '770c1e9dd135c60f5c1ee27dace6660669b2ffb1558d4713f9cd823b3aa8c44c'
# B.1.3: all eleven reviewed LF-normalized sources, including main's newer OLX
# presentation block in admin/index.php. Do not accept an older admin preimage.
REVIEWED_RUNTIME_SHA256 = '3dfc289f54a50687ac5f65f692ef3ac108f6675f13569f0e9c55329b42b47a44'
REPOSITORY = 'gmgardenoutlet-lang/home-garden-outlet'
ENVIRONMENT = 'allegro-infra-production'
CONFIRM = 'DEPLOY_ALLEGRO_INFRA_OAUTH_OFF'
# SINGLE authority for production paths, also used by backup and rollback.
# Order: deny rules first, new libraries before routes/navigation.
ALLOWLIST = (
    'admin/allegro/.htaccess', 'admin/allegro/config.php',
    'admin/allegro/storage.php', 'admin/allegro/http.php',
    'admin/allegro/oauth.php', 'admin/allegro/lib.php',
    'admin/allegro/bridge.js', 'admin/allegro/callback.php',
    'admin/allegro/index.php', 'admin/.htaccess', 'admin/index.php',
)
SHARED_ADDITIONS = {
    'admin/.htaccess': '  RewriteRule ^allegro/callback/?$ allegro/callback.php [END]\n',
    'admin/index.php': '      <a href="/admin/allegro/">Allegro</a>\n',
}
MANIFEST = 'allegro-infra-release-manifest.json'
OFF_PROFILE = json.loads((ROOT / 'scripts/allegro-off-profile.json').read_text(encoding='utf-8'))
require_profile = OFF_PROFILE.get('schema') == 1 and OFF_PROFILE.get('profile') == 'B.1.1-OAUTH-OFF-v1'
if not require_profile:
    raise ValueError('Unknown Allegro OFF profile')


def require(ok, message):
    if not ok:
        raise ValueError(message)


def sha(data):
    return hashlib.sha256(data).hexdigest()


def regular(path):
    require(path.is_file() and not any(p.is_symlink() for p in [path, *path.parents]), 'Nonregular/symlink source rejected')
    return path.read_bytes()


def clean_source(data):
    text = data.decode('utf-8').replace('\r\n', '\n')
    require('-----BEGIN ' not in text and not re.search(r'\beyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]+\.', text), 'Possible embedded credential rejected')
    require(not re.search(r'''(?i)(?:access_token|refresh_token|ALLEGRO_CLIENT_SECRET|ALLEGRO_TOKEN_KEY)['"]?\s*(?:=>|=|:)\s*['"][^'"\s]+['"]''', text), 'Literal secret assignment rejected')
    return text


def expected_files():
    rows = []
    bodies = {}
    reviewed = hashlib.sha256()
    runtime_reviewed = hashlib.sha256()
    for target in ALLOWLIST:
        source = 'hosting/getspace/' + target
        raw = regular(ROOT / source)
        text = clean_source(raw)
        runtime_reviewed.update(target.encode() + b'\0' + text.encode())
        if target.startswith('admin/allegro/'):
            reviewed.update(target.encode() + b'\0' + text.encode())
        previous = []
        if target in SHARED_ADDITIONS:
            addition = SHARED_ADDITIONS[target]
            require(text.count(addition) == 1, 'Shared Allegro delta changed; review required')
            baseline = subprocess.check_output(['git', 'show', BASE + ':' + source], cwd=ROOT).decode('utf-8').replace('\r\n', '\n')
            require(text.replace(addition, '', 1) == baseline, 'Unrelated shared code change; review required')
            previous = [sha(baseline.encode()), sha(baseline.replace('\n', '\r\n').encode())]
        if target == 'admin/allegro/config.php':
            require(sha(text.encode()) == OFF_PROFILE['source_sha256'], 'Unreviewed config source')
            for before, after in OFF_PROFILE['replacements']:
                require(text.count(before) == 1, 'Config transform no longer exact; review required')
                text = text.replace(before, after, 1)
            require(sha(text.encode()) == OFF_PROFILE['payload_sha256'], 'Wrong OFF payload')
        body = text.encode('utf-8')
        rows.append({'source': source, 'target': target, 'source_sha256': sha(raw), 'sha256': sha(body),
                     'allowed_previous_sha256': previous, 'allow_absent': target not in SHARED_ADDITIONS})
        bodies[target] = body
    require(reviewed.hexdigest() == REVIEWED_MODULE_SHA256, 'Module differs from reviewed B.1 source; release blocked')
    require(runtime_reviewed.hexdigest() == REVIEWED_RUNTIME_SHA256, 'Runtime differs from reviewed B.1.3 source; release blocked')
    return rows, bodies


def build(directory):
    require(not directory.exists(), 'Release directory must be new; never reuse a partial build')
    rows, bodies = expected_files()
    commit = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip()
    ref = os.environ.get('GITHUB_REF', subprocess.check_output(['git', 'branch', '--show-current'], cwd=ROOT, text=True).strip())
    fingerprint = sha(json.dumps(rows, sort_keys=True).encode())
    manifest = {'schema': 1, 'commit': commit, 'ref': ref, 'source_sha256': fingerprint,
                'created_utc': dt.datetime.now(dt.timezone.utc).isoformat(), 'required_php': '8.3',
                'qualification_node': '22', 'build_node': subprocess.check_output(['node', '--version'], text=True).strip(),
                'oauth_enabled': False, 'callback_logs_redacted': False, 'sale_api_enabled': False,
                'config_transform': 'B.1.1-OAUTH-OFF-v1', 'files': rows}
    for target, body in bodies.items():
        dest = directory / 'runtime' / target
        dest.parent.mkdir(parents=True, exist_ok=True)
        dest.write_bytes(body)
    (directory / MANIFEST).write_text(json.dumps(manifest, indent=2) + '\n', encoding='utf-8')
    verify(directory)
    return manifest


def verify(directory):
    manifest = json.loads(regular(directory / MANIFEST))
    rows, bodies = expected_files()
    require(manifest.get('schema') == 1 and manifest.get('files') == rows, 'Manifest/source/destination allowlist or hashes differ')
    require(manifest.get('source_sha256') == sha(json.dumps(rows, sort_keys=True).encode()), 'Source fingerprint differs')
    require(manifest.get('commit') == subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip(), 'Wrong release commit')
    require(manifest.get('required_php') == '8.3' and manifest.get('qualification_node') == '22'
            and manifest.get('config_transform') == 'B.1.1-OAUTH-OFF-v1', 'Invalid qualification policy')
    require(all(manifest.get(k) is False for k in ['oauth_enabled', 'callback_logs_redacted', 'sale_api_enabled']), 'Unsafe release flags')
    require(set(manifest) == {'schema', 'commit', 'ref', 'source_sha256', 'created_utc', 'required_php',
                             'qualification_node', 'build_node', 'oauth_enabled', 'callback_logs_redacted',
                             'sale_api_enabled', 'config_transform', 'files'}, 'Unexpected manifest metadata')
    require(dt.datetime.fromisoformat(manifest['created_utc']).utcoffset() == dt.timedelta(0), 'UTC timestamp required')
    if os.environ.get('GITHUB_ACTIONS') == 'true':
        require(manifest['build_node'].startswith('v22.') and subprocess.check_output(['node', '--version'], text=True).startswith('v22.'),
                'Release requires actual Node 22 in CI')
        require(manifest['commit'] == os.environ.get('GITHUB_SHA') and manifest['ref'] == os.environ.get('GITHUB_REF'), 'Wrong Actions release ref')
    actual = set()
    allowed_dirs = {'runtime', 'runtime/admin', 'runtime/admin/allegro'}
    for path in directory.rglob('*'):
        require(not path.is_symlink(), 'Release symlink rejected')
        if path.is_file():
            actual.add(path.relative_to(directory).as_posix())
        else:
            require(path.is_dir() and path.relative_to(directory).as_posix() in allowed_dirs, 'Unexpected release directory/special entry')
    require(actual == {MANIFEST, *('runtime/' + p for p in ALLOWLIST)}, 'Unexpected/missing artifact file')
    for target, body in bodies.items():
        require(regular(directory / 'runtime' / target) == body, 'Payload changed after qualification')
    # Full exact comparison above includes http.php and its /sale-denying policy.
    return manifest


def execution_guard():
    require(os.environ.get('GITHUB_ACTIONS') == 'true' and os.environ.get('GITHUB_REPOSITORY') == REPOSITORY,
            'Remote operations are restricted to the reviewed repository Actions job')
    require(os.environ.get('ALLEGRO_DEPLOY_CONFIRM') == CONFIRM, 'Explicit deployment confirmation missing')
    require(os.environ.get('GITHUB_REF') == 'refs/heads/main', 'Remote operations require main')


def environment_guard():
    execution_guard()
    token = os.environ.get('GH_TOKEN', '')
    require(bool(token), 'Environment inspection token missing')
    req = urllib.request.Request(f'https://api.github.com/repos/{REPOSITORY}/environments/{ENVIRONMENT}',
                                 headers={'Authorization': 'Bearer ' + token, 'Accept': 'application/vnd.github+json',
                                          'X-GitHub-Api-Version': '2022-11-28'})
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, req, fp, code, msg, headers, newurl):
            return None
    with urllib.request.build_opener(NoRedirect()).open(req, timeout=20) as response:
        data = json.load(response)
    validate_environment(data)
    require(re.fullmatch(r'hgo_admin=[A-Za-z0-9,-]{16,256}', os.environ.get('ALLEGRO_SMOKE_ADMIN_COOKIE', '')) is not None,
            'Fresh authenticated smoke session missing')


def validate_environment(data):
    # Single-owner approval is the manual dispatch plus exact confirmation.
    # Independent reviewers/prevent_self_review are optional, not prerequisites.
    policy = data.get('deployment_branch_policy') or {}
    require(data.get('can_admins_bypass') is False and
            ((policy.get('protected_branches') is True) != (policy.get('custom_branch_policies') is True)),
            'Environment bypass/branch protection not verified')


class FTPS:
    """No listing, mirroring, private provisioning, SITE/CHMOD or recursive deletion."""
    def __init__(self):
        host = os.environ.get('ALLEGRO_FTP_SERVER', '')
        root = os.environ.get('ALLEGRO_FTP_PUBLIC_DIR', '').rstrip('/')
        require(re.fullmatch(r'[A-Za-z0-9.-]+', host) is not None, 'Invalid FTPS host')
        parts = PurePosixPath(root).parts
        require(parts and parts[-1] == 'public_html' and all(p not in ('.', '..') for p in root.split('/'))
                and re.fullmatch(r'/?[A-Za-z0-9_./-]+', root) is not None, 'Target must be an explicit public_html path')
        user, password = os.environ.get('ALLEGRO_FTP_USERNAME', ''), os.environ.get('ALLEGRO_FTP_PASSWORD', '')
        require(bool(user and password), 'Dedicated Allegro FTPS credentials missing')
        self.ftp = ftplib.FTP_TLS(context=ssl.create_default_context(), timeout=30)
        self.ftp.connect(host, 21)
        self.ftp.login(user, password)
        self.ftp.prot_p()
        self.ftp.cwd(root)
        roots = dict(self.ftp.mlsd(facts=['type']))
        require(roots.get('admin', {}).get('type') == 'dir', 'Unsafe/missing admin code directory')
        admins = dict(self.ftp.mlsd('admin', facts=['type']))
        require('allegro' not in admins or admins['allegro'].get('type') == 'dir', 'Unsafe Allegro code directory')

    def read(self, target):
        require(target in ALLOWLIST, 'Remote read outside allowlist')
        buf = io.BytesIO()
        def receive(chunk):
            require(buf.tell() + len(chunk) <= 1024 * 1024, 'Remote code exceeds size limit')
            buf.write(chunk)
        self.ftp.retrbinary('RETR ' + target, receive)
        return buf.getvalue()

    def missing(self, target):
        # MLST gives a positive type for existing entries; a 550 alone is ambiguous.
        # Absence is established by MLSD of the code directory, restricted to names/types.
        require(target in ALLOWLIST, 'Remote stat outside allowlist')
        parent, name = target.rsplit('/', 1)
        try:
            entries = dict(self.ftp.mlsd(parent, facts=['type']))
        except ftplib.error_perm:
            if parent == 'admin/allegro':
                admins = dict(self.ftp.mlsd('admin', facts=['type']))
                require('allegro' not in admins, 'Cannot inspect existing Allegro code directory')
                return True
            raise
        if name in entries:
            require(entries[name].get('type') == 'file', 'Nonregular remote target rejected')
            return False
        return True

    def write(self, target, body):
        require(target in ALLOWLIST, 'Remote write outside allowlist')
        self.ftp.storbinary('STOR ' + target, io.BytesIO(body))

    def delete(self, target):
        require(target in ALLOWLIST, 'Remote delete outside allowlist')
        self.ftp.delete(target)

    def ensure_module_dir(self):
        admins = dict(self.ftp.mlsd('admin', facts=['type']))
        if 'allegro' not in admins:
            self.ftp.mkd('admin/allegro')
        else:
            require(admins['allegro'].get('type') == 'dir', 'Unsafe remote Allegro directory')

    def close(self):
        self.ftp.close()


def backup(directory, backup_dir, remote):
    manifest = verify(directory)
    require(not backup_dir.exists(), 'Backup directory must be new')
    previous = {}
    # Complete preflight BEFORE any production write. Unknown existing module rejected.
    for row in manifest['files']:
        target = row['target']
        if remote.missing(target):
            require(row['allow_absent'], 'Required shared admin file missing')
            previous[target] = None
        else:
            body = remote.read(target)
            require(sha(body) in [row['sha256'], *row['allowed_previous_sha256']], 'Remote code conflict; no transfer allowed')
            previous[target] = body
    backup_dir.mkdir(parents=True, mode=0o700)
    records = {}
    for target, body in previous.items():
        records[target] = None if body is None else sha(body)
        if body is not None:
            path = backup_dir / 'files' / target
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(body)
    state = {'release_sha256': sha(regular(directory / MANIFEST)), 'before': records, 'attempted': []}
    save_state(backup_dir, state)
    return state


def save_state(directory, state):
    temp = directory / 'state.tmp'
    temp.write_text(json.dumps(state, indent=2), encoding='utf-8')
    temp.replace(directory / 'state.json')


def load_state(directory, backup_dir):
    verify(directory)
    state = json.loads(regular(backup_dir / 'state.json'))
    require(state['release_sha256'] == sha(regular(directory / MANIFEST)) and set(state['before']) == set(ALLOWLIST), 'Wrong/incomplete backup')
    require(len(state['attempted']) == len(set(state['attempted'])) and set(state['attempted']) <= set(ALLOWLIST), 'Invalid rollback journal')
    for target, digest in state['before'].items():
        if digest is not None:
            require(sha(regular(backup_dir / 'files' / target)) == digest, 'Backup hash mismatch')
    return state


def deploy(directory, backup_dir, remote):
    state = load_state(directory, backup_dir)
    require(not state['attempted'], 'Deploy already started; rollback/review required')
    # Recheck ALL preimages, including absent names, before first upload.
    for target, digest in state['before'].items():
        absent = remote.missing(target)
        require(absent if digest is None else not absent and sha(remote.read(target)) == digest, 'Concurrent code change; deploy blocked')
    remote.ensure_module_dir()
    for target in ALLOWLIST:
        absent = remote.missing(target)
        digest = state['before'][target]
        require(absent if digest is None else not absent and sha(remote.read(target)) == digest, 'Concurrent per-file code change')
        state['attempted'].append(target)  # Persist intent before STOR, including partial failures.
        save_state(backup_dir, state)
        body = regular(directory / 'runtime' / target)
        remote.write(target, body)
        require(remote.read(target) == body, 'Upload readback mismatch')


def rollback(directory, backup_dir, remote):
    state = load_state(directory, backup_dir)
    failed = []
    # Best effort across all attempted files, never touch any unattempted path.
    for target in reversed(state['attempted']):
        try:
            if target == 'admin/allegro/.htaccess' and any(p.startswith('admin/allegro/') for p in failed):
                continue  # Retain the deny rules if any module recovery failed.
            if not remote.missing(target):
                current = remote.read(target)
                payload = regular(directory / 'runtime' / target)
                require(sha(current) == state['before'][target] or payload.startswith(current),
                        'Concurrent code change during rollback; operator review required')
            if state['before'][target] is None:
                if not remote.missing(target):
                    remote.delete(target)
                require(remote.missing(target), 'New code removal failed')
            else:
                body = regular(backup_dir / 'files' / target)
                remote.write(target, body)
                require(remote.read(target) == body, 'Restore readback mismatch')
        except Exception:
            failed.append(target)
    require(not failed, 'Rollback incomplete; operator intervention required for allowlisted code files')
    state['rolled_back'] = True
    save_state(backup_dir, state)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['build', 'verify', 'guard', 'backup', 'deploy', 'rollback'])
    parser.add_argument('--release', type=Path, default=ROOT / '.local-cache/allegro-infra-release')
    parser.add_argument('--backup', type=Path)
    parser.add_argument('--execute', action='store_true')
    args = parser.parse_args()
    if args.action == 'build':
        build(args.release)
    elif args.action == 'verify':
        verify(args.release)
    else:
        require(args.execute, 'Remote action requires explicit --execute')
        if args.action == 'rollback':
            # Recovery must not depend on a still-reachable GitHub API or fresh smoke cookie.
            # Exact prior release/backup journal is checked again by rollback().
            execution_guard()
        else:
            environment_guard()
        if args.action != 'guard':
            require(args.backup is not None, 'Explicit local code backup path required')
            verify(args.release)
            remote = FTPS()
            try:
                {'backup': backup, 'deploy': deploy, 'rollback': rollback}[args.action](args.release, args.backup, remote)
            finally:
                remote.close()
    print('PASS: Allegro infrastructure ' + args.action)


if __name__ == '__main__':
    try:
        main()
    except Exception:
        # Raw FTP/HTTP errors can carry credentials/paths. Do not echo them.
        print('FAIL: Allegro infrastructure operation stopped; no raw transport/configuration output.', file=sys.stderr)
        sys.exit(1)
