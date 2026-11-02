"""Separate, fail-closed Device Ready transition. Imports never access production."""
import argparse
import datetime as dt
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import re
import stat
import sys
import urllib.error
import urllib.parse
import urllib.request
import zipfile


ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('immutable_release', ROOT / 'scripts/allegro-infra-release.py')
core = importlib.util.module_from_spec(spec)
spec.loader.exec_module(core)
REPO = core.REPOSITORY
WORKFLOW = '.github/workflows/deploy-allegro-device-ready-getspace.yml'
PROFILE = 'DEVICE_READY_CONFIG_OFF'
DEPLOY_CONFIRM = 'DEPLOY_ALLEGRO_DEVICE_READY_CONFIG_OFF'
RECOVERY_CONFIRM = 'ROLLBACK_ALLEGRO_DEVICE_READY_TO_IMMUTABLE_OFF'
# Deliberately disabled. Pin a number only in a separately reviewed authorization step.
AUTHORIZED_PRODUCTION_RUN = None
TARGET = 'admin/allegro/config.php'
OFF_SHA = '102d4086ae3f0ee00024c295621c0216f6c260474dea60d4469b2e84dc60478e'
READY_SHA = '968b3cd989a56db20d2d8cb33c6f41d7aa334630354a1a5303fd247f4b3a5497'
# Canonical sorted JSON of all 11 hashes, calculated from qualified #20/#21 artifacts.
OFF_VECTOR_SHA = '98cbb79ee4c38db7319d9e95c108bcd546b2c761a15af6c16618a926a5967ed9'
RELEASE_ARTIFACT = 'allegro-device-ready-release'
BACKUP_ARTIFACT = 'allegro-device-ready-backup-before'
INTENT_ARTIFACT = 'allegro-device-ready-stor-intent'
TRANSITION = 'B.1.1-OAUTH-OFF-v1->DEVICE_READY_CONFIG_OFF'
LIMIT = 16 * 1024 * 1024


class Failure(ValueError):
    pass


def require(ok, code='POLICY_REJECTED'):
    if not ok:
        raise Failure(code)


def digest(data):
    return hashlib.sha256(data).hexdigest()


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':')).encode()


def integer(value):
    require(re.fullmatch(r'[1-9][0-9]{0,19}', str(value)) is not None, 'INVALID_ID')
    return int(value)


def now():
    return dt.datetime.now(dt.timezone.utc).isoformat()


def vectors():
    _, off = core.expected_files()
    rows, ready = core.expected_files(core.DEVICE_READY_PROFILE)
    off_map = {p: digest(b) for p, b in off.items()}
    ready_map = {p: digest(b) for p, b in ready.items()}
    require(digest(canonical(off_map)) == OFF_VECTOR_SHA, 'OFF_BASELINE_CHANGED')
    require(off_map[TARGET] == OFF_SHA and ready_map[TARGET] == READY_SHA, 'CONFIG_PROFILE_CHANGED')
    require([p for p in core.ALLOWLIST if off_map[p] != ready_map[p]] == [TARGET], 'UNEXPECTED_RUNTIME_DELTA')
    return rows, off_map, ready_map, ready


def verify_manifest(manifest, files, sha):
    rows, _, _, bodies = vectors()
    require(set(manifest) == {'schema', 'commit', 'ref', 'source_sha256', 'created_utc', 'required_php',
                             'qualification_node', 'build_node', 'oauth_enabled', 'callback_logs_redacted',
                             'sale_api_enabled', 'config_transform', 'files'}, 'MANIFEST_FIELDS')
    require(manifest['schema'] == 1 and manifest['commit'] == sha and manifest['ref'] == 'refs/heads/main', 'MANIFEST_IDENTITY')
    require(manifest['config_transform'] == PROFILE and manifest['oauth_enabled'] == 'private_config'
            and manifest['callback_logs_redacted'] is False and manifest['sale_api_enabled'] is False, 'MANIFEST_PROFILE')
    require(manifest['required_php'] == '8.3' and manifest['qualification_node'] == '22', 'MANIFEST_RUNTIME')
    require(os.environ.get('GITHUB_ACTIONS') != 'true' or manifest['build_node'].startswith('v22.'), 'MANIFEST_NODE')
    require(dt.datetime.fromisoformat(manifest['created_utc']).utcoffset() == dt.timedelta(0), 'MANIFEST_TIME')
    require(manifest['files'] == rows and manifest['source_sha256'] == digest(json.dumps(rows, sort_keys=True).encode()), 'MANIFEST_HASHES')
    require(set(files) == {'runtime/' + p for p in core.ALLOWLIST}, 'ARTIFACT_PATHS')
    for p, body in bodies.items():
        require(files['runtime/' + p] == body, 'ARTIFACT_PAYLOAD')
    return manifest


def verify_ready(directory, sha):
    core.verify(directory)
    raw = core.regular(directory / core.MANIFEST)
    files = {'runtime/' + p: core.regular(directory / 'runtime' / p) for p in core.ALLOWLIST}
    return verify_manifest(json.loads(raw), files, sha)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class GitHub:
    """GET only. Never send GitHub authorization to artifact CDN hosts."""
    def __init__(self, token):
        require(bool(token), 'GITHUB_TOKEN_MISSING')
        self.token = token
        self.opener = urllib.request.build_opener(NoRedirect())

    def request(self, suffix, binary=False):
        require(suffix.startswith('/') and not any(x in suffix for x in ['..', '\r', '\n', '://']), 'GITHUB_PATH')
        req = urllib.request.Request('https://api.github.com/repos/' + REPO + suffix,
            headers={'Authorization': 'Bearer ' + self.token, 'Accept': 'application/vnd.github+json',
                     'X-GitHub-Api-Version': '2022-11-28'}, method='GET')
        try:
            response = self.opener.open(req, timeout=30)
        except urllib.error.HTTPError as exc:
            require(binary and exc.code == 302, 'GITHUB_READ_FAILED')
            url = exc.headers.get('Location', '')
            parsed = urllib.parse.urlsplit(url)
            require(parsed.scheme == 'https' and not parsed.username and not parsed.password and parsed.port in (None, 443)
                    and (parsed.hostname or '').endswith(('.blob.core.windows.net', '.githubusercontent.com')), 'ARTIFACT_HOST')
            response = self.opener.open(urllib.request.Request(url, method='GET'), timeout=30)
        with response:
            body = response.read(LIMIT + 1)
        require(len(body) <= LIMIT, 'RESPONSE_LIMIT')
        return body if binary else json.loads(body)

    def get(self, suffix):
        return self.request(suffix)

    def download(self, artifact_id):
        return self.request('/actions/artifacts/' + str(integer(artifact_id)) + '/zip', binary=True)


def run_metadata(client, run_id, sha, completed=False):
    run = client.get('/actions/runs/' + str(integer(run_id)))
    require(run.get('id') == integer(run_id) and run.get('repository', {}).get('full_name') == REPO, 'SOURCE_REPOSITORY')
    require(run.get('path') == WORKFLOW and run.get('event') == 'workflow_dispatch'
            and run.get('head_branch') == 'main' and run.get('head_sha') == sha
            and run.get('run_attempt') == 1, 'SOURCE_IDENTITY')
    require(not completed or run.get('status') == 'completed', 'SOURCE_NOT_COMPLETED')
    return run


def execution_guard(action, env, client):
    mode = {'inspect': 'inspect-device-ready', 'backup': 'deploy-device-ready', 'intent': 'deploy-device-ready',
            'deploy': 'deploy-device-ready', 'rollback-live': 'deploy-device-ready', 'recover': 'rollback-device-ready'}[action]
    job = 'inspect' if action == 'inspect' else 'recovery' if action == 'recover' else 'production'
    sha = env.get('DR_RELEASE_SHA', '')
    require(re.fullmatch('[0-9a-f]{40}', sha) is not None and sha == env.get('GITHUB_SHA') == env.get('GITHUB_WORKFLOW_SHA'), 'SOURCE_SHA')
    require(env.get('GITHUB_ACTIONS') == 'true' and env.get('GITHUB_REPOSITORY') == REPO
            and env.get('GITHUB_EVENT_NAME') == 'workflow_dispatch' and env.get('GITHUB_REF') == 'refs/heads/main'
            and env.get('GITHUB_WORKFLOW_REF') == REPO + '/' + WORKFLOW + '@refs/heads/main'
            and env.get('GITHUB_JOB') == job and env.get('GITHUB_RUN_ATTEMPT') == '1'
            and env.get('DR_MODE') == mode, 'EXECUTION_IDENTITY')
    confirmation = '' if action == 'inspect' else RECOVERY_CONFIRM if action == 'recover' else DEPLOY_CONFIRM
    require(env.get('DR_CONFIRMATION', '') == confirmation, 'CONFIRMATION')
    if action != 'inspect':
        require(AUTHORIZED_PRODUCTION_RUN is not None, 'TRANSITION_NOT_AUTHORIZED')
    run = run_metadata(client, env.get('GITHUB_RUN_ID', ''), sha)
    require(run['run_number'] == integer(env.get('GITHUB_RUN_NUMBER', '')), 'RUN_NUMBER')
    if action not in ('inspect', 'recover'):
        require(run['run_number'] == AUTHORIZED_PRODUCTION_RUN, 'RUN_NOT_AUTHORIZED')
    require(client.get('/branches/main').get('commit', {}).get('sha') == sha, 'MAIN_DRIFT')
    return run


def environment_guard(client, cookie_required, env):
    data = client.get('/environments/' + core.ENVIRONMENT)
    require(data.get('name') == core.ENVIRONMENT, 'ENVIRONMENT_IDENTITY')
    core.validate_environment(data)
    policy = data.get('deployment_branch_policy') or {}
    require(policy.get('custom_branch_policies') is True and policy.get('protected_branches') is False, 'ENVIRONMENT_BRANCH_POLICY')
    rules = client.get('/environments/' + core.ENVIRONMENT + '/deployment-branch-policies')
    require(rules.get('total_count') == 1 and [(r.get('name'), r.get('type')) for r in rules.get('branch_policies', [])]
            == [('main', 'branch')], 'ENVIRONMENT_MAIN_ONLY')
    if cookie_required:
        require(re.fullmatch(r'hgo_admin=[A-Za-z0-9,-]{16,256}', env.get('ALLEGRO_SMOKE_ADMIN_COOKIE', '')) is not None, 'SMOKE_COOKIE')


def archive(body, allowed):
    require(len(body) <= LIMIT, 'ARCHIVE_LIMIT')
    with zipfile.ZipFile(io.BytesIO(body)) as z:
        entries = z.infolist()
        require(len(entries) == len(allowed) and {x.filename for x in entries} == set(allowed), 'ARCHIVE_PATHS')
        require(sum(x.file_size for x in entries) <= LIMIT, 'ARCHIVE_LIMIT')
        for x in entries:
            require(not x.is_dir() and not stat.S_ISLNK(x.external_attr >> 16) and not (x.flag_bits & 1), 'ARCHIVE_ENTRY')
        return {x.filename: z.read(x) for x in entries}


def artifact(client, run, name, artifact_id=None, expected_digest=None):
    data = client.get('/actions/runs/' + str(run['id']) + '/artifacts?per_page=100')
    require(data.get('total_count', 101) <= 100, 'ARTIFACT_AMBIGUOUS')
    matches = [x for x in data.get('artifacts', []) if x.get('name') == name]
    require(len(matches) == 1, 'ARTIFACT_AMBIGUOUS')
    meta = matches[0]
    require(not meta.get('expired') and (artifact_id is None or meta.get('id') == integer(artifact_id)), 'ARTIFACT_ID')
    owner = meta.get('workflow_run', {})
    require(owner.get('id') == run['id'] and owner.get('head_sha') == run['head_sha']
            and owner.get('repository_id') == run.get('repository', {}).get('id'), 'ARTIFACT_SOURCE')
    value = meta.get('digest', '')
    require(re.fullmatch('sha256:[0-9a-f]{64}', value) is not None, 'ARTIFACT_DIGEST')
    require(expected_digest is None or value == 'sha256:' + expected_digest, 'ARTIFACT_DIGEST')
    body = client.download(meta['id'])
    require(digest(body) == value[7:], 'ARTIFACT_DIGEST')
    return meta, body


def qualified_source(client, run_id, sha, inspect=False):
    run = run_metadata(client, run_id, sha, completed=True)
    require(run.get('conclusion') == 'success', 'QUALIFICATION_FAILED')
    jobs = client.get('/actions/runs/' + str(run['id']) + '/jobs?per_page=100')
    require(jobs.get('total_count', 101) <= 100, 'QUALIFICATION_JOBS')
    entries = jobs.get('jobs', [])
    require(len(entries) == 6 and len({x.get('name') for x in entries}) == 6, 'QUALIFICATION_JOBS')
    results = {x['name']: x.get('conclusion') for x in entries}
    require(all(results.get(x) == 'success' for x in ['QUALIFY', 'BUILD', 'VERIFY']), 'QUALIFICATION_JOBS')
    require(results.get('PRODUCTION') == 'skipped' and results.get('RECOVERY') == 'skipped'
            and results.get('INSPECT') == ('success' if inspect else 'skipped'), 'QUALIFICATION_MODE')
    _, body = artifact(client, run, RELEASE_ARTIFACT)
    files = archive(body, {core.MANIFEST, *('runtime/' + p for p in core.ALLOWLIST)})
    manifest = json.loads(files.pop(core.MANIFEST))
    verify_manifest(manifest, files, sha)
    if inspect:
        _, body = artifact(client, run, 'allegro-device-ready-inspection')
        rows = json.loads(archive(body, {'inspection.json'})['inspection.json'])
        validate_rows(rows)
    return run


def classify(path, exists, body, maps=None):
    _, off, target, _ = vectors() if maps is None else maps
    h = digest(body) if exists else None
    state = 'missing' if not exists else 'already_target' if h == target[path] else 'approved_off_preimage' if h == off[path] else 'conflict'
    return {'path': path, 'exists': exists, 'size': len(body) if exists else 0, 'sha256': h, 'classification': state}


def validate_rows(rows):
    require(isinstance(rows, list) and len(rows) == 11 and {x['path'] for x in rows} == set(core.ALLOWLIST), 'RUNTIME_PATHS')
    _, off, target, _ = vectors()
    observed = {}
    for row in rows:
        require(set(row) == {'path', 'exists', 'size', 'sha256', 'classification'}, 'INSPECTION_FIELDS')
        require(row['exists'] is True and isinstance(row['size'], int) and row['size'] > 0, 'MANUAL_INTERVENTION_REQUIRED')
        h = row['sha256']
        expected = 'already_target' if h == target[row['path']] else 'approved_off_preimage' if h == off[row['path']] else 'conflict'
        require(row['classification'] == expected and expected != 'conflict', 'MANUAL_INTERVENTION_REQUIRED')
        observed[row['path']] = h
    require(observed == off or observed == target, 'MANUAL_INTERVENTION_REQUIRED')
    return 'OFF' if observed == off else 'READY'


def read_vector(remote):
    rows, files = [], {}
    maps = vectors()
    for path in core.ALLOWLIST:
        exists = not remote.missing(path)
        body = remote.read(path) if exists else b''
        rows.append(classify(path, exists, body, maps))
        if exists:
            files[path] = body
    return rows, files


def store_json(path, value):
    path.parent.mkdir(parents=True, exist_ok=True)
    require(not path.exists(), 'LOCAL_FILE_EXISTS')
    with path.open('xb') as f:
        f.write(json.dumps(value, sort_keys=True, indent=2).encode() + b'\n')
        f.flush()
        os.fsync(f.fileno())


def context(run):
    return {'repository': REPO, 'workflow_path': WORKFLOW, 'workflow_id': run['workflow_id'],
            'source_commit_sha': run['head_sha'], 'source_run_id': run['id'],
            'run_number': run['run_number'], 'run_attempt': run['run_attempt'], 'production_job': 'production'}


def backup(directory, backup_dir, remote, run):
    verify_ready(directory, run['head_sha'])
    require(not backup_dir.exists(), 'BACKUP_EXISTS')
    rows, files = read_vector(remote)
    state = validate_rows(rows)
    if state == 'READY':
        return False
    for path, body in files.items():
        dest = backup_dir / 'files' / path
        dest.parent.mkdir(parents=True, exist_ok=True)
        dest.write_bytes(body)
    raw = core.regular(directory / core.MANIFEST)
    (backup_dir / 'release-manifest.json').write_bytes(raw)
    store_json(backup_dir / 'state.json', {'schema': 1, **context(run), 'before': {p: digest(b) for p, b in files.items()},
               'release_manifest_sha256': digest(raw), 'attempted': []})
    return True


def validate_backup(files, run):
    expected = {'state.json', 'release-manifest.json', *('files/' + p for p in core.ALLOWLIST)}
    require(set(files) == expected, 'BACKUP_PATHS')
    state = json.loads(files['state.json'])
    require(set(state) == {'schema', *context(run), 'before', 'release_manifest_sha256', 'attempted'}
            and state['schema'] == 1 and all(state.get(k) == v for k, v in context(run).items())
            and state['attempted'] == [], 'BACKUP_IDENTITY')
    _, off, _, ready = vectors()
    require(state['before'] == off, 'BACKUP_PREIMAGES')
    for p, h in off.items():
        require(digest(files['files/' + p]) == h, 'BACKUP_HASHES')
    require(digest(files['release-manifest.json']) == state['release_manifest_sha256'], 'BACKUP_MANIFEST')
    verify_manifest(json.loads(files['release-manifest.json']), {'runtime/' + p: b for p, b in ready.items()}, run['head_sha'])
    return state


def make_intent(client, run, backup_id, backup_digest):
    meta, raw = artifact(client, run, BACKUP_ARTIFACT, backup_id, backup_digest)
    files = archive(raw, {'state.json', 'release-manifest.json', *('files/' + p for p in core.ALLOWLIST)})
    state = validate_backup(files, run)
    return {'schema': 1, **context(run), 'transition': TRANSITION, 'target_path': TARGET,
            'off_preimage_sha256': OFF_SHA, 'device_ready_target_sha256': READY_SHA,
            'release_manifest_sha256': state['release_manifest_sha256'], 'backup_artifact_id': meta['id'],
            'backup_artifact_sha256': meta['digest'][7:], 'state': 'STOR_INTENT', 'timestamp_utc': now()}


def recovery_evidence(client, source_run, intent_id=None, intent_digest=None):
    require(AUTHORIZED_PRODUCTION_RUN is not None and source_run['run_number'] == AUTHORIZED_PRODUCTION_RUN, 'SOURCE_RUN_NOT_AUTHORIZED')
    _, raw = artifact(client, source_run, INTENT_ARTIFACT, intent_id, intent_digest)
    intent = json.loads(archive(raw, {'intent.json'})['intent.json'])
    require(set(intent) == {'schema', *context(source_run), 'transition', 'target_path', 'off_preimage_sha256',
            'device_ready_target_sha256', 'release_manifest_sha256', 'backup_artifact_id', 'backup_artifact_sha256',
            'state', 'timestamp_utc'}, 'INTENT_FIELDS')
    require(intent['schema'] == 1 and all(intent.get(k) == v for k, v in context(source_run).items()), 'INTENT_IDENTITY')
    require(intent['transition'] == TRANSITION and intent['target_path'] == TARGET and intent['off_preimage_sha256'] == OFF_SHA
            and intent['device_ready_target_sha256'] == READY_SHA and intent['state'] == 'STOR_INTENT', 'INTENT_TRANSITION')
    require(dt.datetime.fromisoformat(intent['timestamp_utc']).utcoffset() == dt.timedelta(0), 'INTENT_TIME')
    _, raw = artifact(client, source_run, BACKUP_ARTIFACT, intent['backup_artifact_id'], intent['backup_artifact_sha256'])
    files = archive(raw, {'state.json', 'release-manifest.json', *('files/' + p for p in core.ALLOWLIST)})
    state = validate_backup(files, source_run)
    require(intent['release_manifest_sha256'] == state['release_manifest_sha256'], 'INTENT_MANIFEST')
    return intent, files


def deploy(directory, remote, run, client, env):
    verify_ready(directory, run['head_sha'])
    intent_id = integer(env.get('DR_INTENT_ARTIFACT_ID', ''))
    intent_digest = env.get('DR_INTENT_ARTIFACT_SHA256', '')
    require(re.fullmatch('[0-9a-f]{64}', intent_digest) is not None, 'ARTIFACT_DIGEST')
    intent, _ = recovery_evidence(client, run, intent_id, intent_digest)
    require(intent['release_manifest_sha256'] == digest(core.regular(directory / core.MANIFEST)), 'DEPLOY_MANIFEST')
    rows, _ = read_vector(remote)
    require(validate_rows(rows) == 'OFF', 'PREIMAGE_CHANGED')
    execution_guard('deploy', env, client)
    # FTPS has no CAS; external writes still require a controlled maintenance window.
    require(not remote.missing(TARGET) and digest(remote.read(TARGET)) == OFF_SHA, 'PREIMAGE_CHANGED')
    body = core.regular(directory / 'runtime' / TARGET)
    require(digest(body) == READY_SHA, 'TARGET_CHANGED')
    remote.write(TARGET, body)
    require(digest(remote.read(TARGET)) == READY_SHA, 'READBACK_FAILED')
    rows, _ = read_vector(remote)
    require(validate_rows(rows) == 'READY', 'FINAL_VECTOR_FAILED')


def recover(remote, source_run, client, evidence=None):
    _, files = evidence if evidence is not None else recovery_evidence(client, source_run)
    validate_backup(files, source_run)
    rows, _ = read_vector(remote)
    state = validate_rows(rows)  # Unknown/partial/missing -> no STOR, even on the same runner.
    if state == 'OFF':
        return False
    require(not remote.missing(TARGET) and digest(remote.read(TARGET)) == READY_SHA, 'MANUAL_INTERVENTION_REQUIRED')
    body = files['files/' + TARGET]
    require(digest(body) == OFF_SHA, 'BACKUP_HASHES')
    remote.write(TARGET, body)
    require(digest(remote.read(TARGET)) == OFF_SHA, 'RECOVERY_READBACK_FAILED')
    rows, _ = read_vector(remote)
    require(validate_rows(rows) == 'OFF', 'RECOVERY_VECTOR_FAILED')
    return True


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['verify', 'guard', 'inspect', 'backup', 'intent', 'deploy', 'rollback-live', 'recover'])
    parser.add_argument('--release', type=Path, default=ROOT / '.local-cache/allegro-device-ready-release')
    parser.add_argument('--backup', type=Path, default=ROOT / '.local-cache/device-ready-backup')
    parser.add_argument('--report', type=Path, default=ROOT / '.local-cache/device-ready-inspection/inspection.json')
    parser.add_argument('--intent', type=Path, default=ROOT / '.local-cache/device-ready-intent/intent.json')
    parser.add_argument('--execute', action='store_true')
    args = parser.parse_args()
    env = os.environ
    sha = env.get('DR_RELEASE_SHA', env.get('GITHUB_SHA', ''))
    if args.action == 'verify':
        verify_ready(args.release, sha)
        return
    require(args.execute, 'EXPLICIT_EXECUTION_REQUIRED')
    # Current checkout must match the explicitly approved source, including for recovery.
    verify_ready(args.release, sha)
    client = GitHub(env.get('GH_TOKEN', ''))
    action = 'backup' if args.action == 'guard' else args.action
    run = execution_guard(action, env, client)
    environment_guard(client, args.action != 'inspect', env)
    if args.action not in ('recover', 'rollback-live'):
        qualified_source(client, env.get('DR_QUALIFICATION_RUN_ID', ''), sha)
        if args.action != 'inspect':
            qualified_source(client, env.get('DR_INSPECTION_RUN_ID', ''), sha, inspect=True)
    if args.action == 'guard':
        return
    if args.action == 'intent':
        store_json(args.intent, make_intent(client, run, env.get('DR_BACKUP_ARTIFACT_ID', ''), env.get('DR_BACKUP_ARTIFACT_SHA256', '')))
        return
    evidence = None
    if args.action in ('recover', 'rollback-live'):
        source = run if args.action == 'rollback-live' else run_metadata(client, env.get('DR_RECOVERY_RUN_ID', ''), sha, completed=True)
        jobs = client.get('/actions/runs/' + str(source['id']) + '/jobs?per_page=100')
        require(jobs.get('total_count', 101) <= 100
                and len([x for x in jobs.get('jobs', []) if x.get('name') == 'PRODUCTION'
                         and x.get('conclusion') != 'skipped']) == 1, 'SOURCE_PRODUCTION_JOB')
        evidence = recovery_evidence(client, source)
    remote = core.ReadOnlyFTPS() if args.action == 'inspect' else core.FTPS()
    try:
        if args.action == 'inspect':
            rows, _ = read_vector(remote)
            store_json(args.report, rows)
            validate_rows(rows)
        elif args.action == 'backup':
            required = backup(args.release, args.backup, remote, run)
            output = env.get('GITHUB_OUTPUT')
            if output:
                with open(output, 'a', encoding='utf-8') as f:
                    f.write('required=' + str(required).lower() + '\n')
        elif args.action == 'deploy':
            deploy(args.release, remote, run, client, env)
        else:
            recover(remote, source, client, evidence)
    finally:
        remote.close()


if __name__ == '__main__':
    try:
        main()
        print('PASS: Device Ready operation')
    except Exception as exc:
        # Never print raw exception, transport/configuration, response or artifact bytes.
        code = 'MANUAL_INTERVENTION_REQUIRED' if isinstance(exc, Failure) and str(exc) == 'MANUAL_INTERVENTION_REQUIRED' else 'OPERATION_REJECTED'
        print('FAIL: Device Ready [' + code + ']', file=sys.stderr)
        sys.exit(1)
