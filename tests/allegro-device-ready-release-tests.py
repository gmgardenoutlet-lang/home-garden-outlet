"""Synthetic Device Ready transition tests. No network or real credentials."""
import copy
from email.message import Message
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch
import zipfile

ROOT = Path(__file__).resolve().parents[1]

def load(name, path):
    spec = importlib.util.spec_from_file_location(name, ROOT / path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

r = load('device_release', 'scripts/allegro-device-ready-release.py')
s = load('device_smoke', 'scripts/allegro-device-ready-smoke.py')
SHA = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip()


def zipped(files):
    stream = io.BytesIO()
    with zipfile.ZipFile(stream, 'w', zipfile.ZIP_DEFLATED) as z:
        for path, body in files.items():
            z.writestr(path, body)
    return stream.getvalue()


class Remote:
    def __init__(self, files):
        self.files = dict(files)
        self.trace = []
        self.hook = None
        self.interrupt = False
        self.bad_readback = False

    def missing(self, path):
        assert path in r.core.ALLOWLIST
        self.trace.append(('exists', path))
        return path not in self.files

    def read(self, path):
        assert path in r.core.ALLOWLIST
        self.trace.append(('read', path))
        if self.hook:
            self.hook(self, path)
        return self.files[path]

    def write(self, path, body):
        assert path == r.TARGET
        self.trace.append(('STOR', path))
        self.files[path] = body[:10] if self.interrupt else b'bad-readback' if self.bad_readback else body
        if self.interrupt:
            raise OSError('synthetic interruption')

    def stores(self):
        return [x for x in self.trace if x[0] == 'STOR']


class API:
    def __init__(self):
        self.runs, self.artifacts, self.blobs, self.jobs = {}, {}, {}, {}
        self.trace = []
        self.main = SHA
        self.env = {'name':r.core.ENVIRONMENT, 'can_admins_bypass':False,
                    'protection_rules':[], 'deployment_branch_policy':{'protected_branches':False,'custom_branch_policies':True}}
        self.rules = {'total_count':1,'branch_policies':[{'name':'main','type':'branch'}]}

    def add_run(self, id=500, number=40):
        run = {'id':id,'repository':{'id':123,'full_name':r.REPO},'path':r.WORKFLOW,
               'event':'workflow_dispatch','head_branch':'main','head_sha':SHA,'run_attempt':1,
               'run_number':number,'workflow_id':99,'status':'completed','conclusion':'success'}
        self.runs[id] = run
        self.artifacts[id] = []
        self.jobs[id] = [{'name':n,'conclusion':'success' if n in ('QUALIFY','BUILD','VERIFY') else 'skipped'}
                         for n in ('QUALIFY','BUILD','VERIFY','INSPECT','PRODUCTION','RECOVERY')]
        return run

    def upload(self, run, name, files):
        id = len(self.blobs)+1
        body = zipped(files)
        meta = {'id':id,'name':name,'expired':False,'digest':'sha256:'+r.digest(body),
                'workflow_run':{'id':run['id'],'head_sha':SHA,'repository_id':123}}
        self.blobs[id] = body
        self.artifacts[run['id']].append(meta)
        self.trace.append(('uploaded',name))
        return meta

    def get(self, path):
        if path == '/branches/main':return {'commit':{'sha':self.main}}
        if path.endswith('/deployment-branch-policies'):return self.rules
        if path.startswith('/environments/'):return self.env
        bits = path.split('/')
        id = int(bits[3])
        if len(bits)==4:return self.runs[id]
        if bits[4].startswith('artifacts'):return {'total_count':len(self.artifacts[id]),'artifacts':self.artifacts[id]}
        if bits[4].startswith('jobs'):return {'total_count':len(self.jobs[id]),'jobs':self.jobs[id]}
        raise AssertionError('unexpected GET')

    def download(self, id):
        self.trace.append(('download',id))
        return self.blobs[id]


class Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory(dir=ROOT / '.local-cache')
        cls.release = Path(cls.temp.name) / 'release'
        with patch.dict(os.environ, {'GITHUB_REF':'refs/heads/main'}):
            r.core.build(cls.release, r.core.DEVICE_READY_PROFILE)
        cls.rows, cls.off_hash, cls.target_hash, cls.ready = r.vectors()
        _, cls.off = r.core.expected_files()
        cls.release_files = {p.relative_to(cls.release).as_posix():p.read_bytes() for p in cls.release.rglob('*') if p.is_file()}

    @classmethod
    def tearDownClass(cls):cls.temp.cleanup()

    def setUp(self):
        self.pin = patch.object(r,'AUTHORIZED_PRODUCTION_RUN',40)
        self.pin.start()
        self.addCleanup(self.pin.stop)
        self.api=API()
        self.run=self.api.add_run()
        self.env={'GITHUB_ACTIONS':'true','GITHUB_REPOSITORY':r.REPO,'GITHUB_EVENT_NAME':'workflow_dispatch',
                  'GITHUB_REF':'refs/heads/main','GITHUB_WORKFLOW_REF':r.REPO+'/'+r.WORKFLOW+'@refs/heads/main',
                  'GITHUB_SHA':SHA,'GITHUB_WORKFLOW_SHA':SHA,'GITHUB_JOB':'production','GITHUB_RUN_ATTEMPT':'1',
                  'GITHUB_RUN_NUMBER':'40','GITHUB_RUN_ID':'500','DR_RELEASE_SHA':SHA,
                  'DR_MODE':'deploy-device-ready','DR_CONFIRMATION':r.DEPLOY_CONFIRM,
                  'ALLEGRO_SMOKE_ADMIN_COOKIE':'hgo_admin='+'x'*32}
        self.remote=Remote(self.off)
        self.tmp=tempfile.TemporaryDirectory(dir=self.temp.name)
        self.addCleanup(self.tmp.cleanup)
        self.backup=Path(self.tmp.name)/'backup'

    def evidence(self):
        r.backup(self.release,self.backup,self.remote,self.run)
        files={p.relative_to(self.backup).as_posix():p.read_bytes() for p in self.backup.rglob('*') if p.is_file()}
        meta=self.api.upload(self.run,r.BACKUP_ARTIFACT,files)
        intent=r.make_intent(self.api,self.run,meta['id'],meta['digest'][7:])
        im=self.api.upload(self.run,r.INTENT_ARTIFACT,{'intent.json':json.dumps(intent).encode()})
        self.env.update(DR_INTENT_ARTIFACT_ID=str(im['id']),DR_INTENT_ARTIFACT_SHA256=im['digest'][7:])
        return files,intent

    def rejected(self, operation):
        with self.assertRaises((r.Failure,ValueError,KeyError,zipfile.BadZipFile)):
            operation()
        self.assertEqual(self.remote.stores(),[])

    def test_guard_accepts_exact_identity(self):r.execution_guard('deploy',self.env,self.api)
    def test_unpinned_default_blocks(self):
        with patch.object(r,'AUTHORIZED_PRODUCTION_RUN',None):self.rejected(lambda:r.execution_guard('deploy',self.env,self.api))
    def test_old_confirmation_rejected(self):
        self.env['DR_CONFIRMATION']='DEPLOY_ALLEGRO_INFRA_OAUTH_OFF'
        self.rejected(lambda:r.execution_guard('deploy',self.env,self.api))
    def test_new_confirmation_rejected_by_old(self):
        env={'GITHUB_ACTIONS':'true','GITHUB_REPOSITORY':r.REPO,'GITHUB_REF':'refs/heads/main',
             'ALLEGRO_DEPLOY_CONFIRM':r.DEPLOY_CONFIRM}
        with patch.dict(os.environ,env,clear=True):
            with self.assertRaises(r.core.GuardFailure) as error:r.core.execution_guard()
            self.assertEqual(error.exception.code,'DEPLOY_CONFIRMATION')
    def test_guard_wrong_identity(self):
        for key,value in [('GITHUB_REPOSITORY','wrong/repo'),('GITHUB_WORKFLOW_REF','wrong'),('GITHUB_REF','refs/heads/other'),
                          ('GITHUB_JOB','qualify'),('GITHUB_SHA','0'*40),('GITHUB_RUN_NUMBER','41'),('GITHUB_RUN_ATTEMPT','2'),
                          ('DR_MODE','deploy'),('GITHUB_WORKFLOW_SHA','0'*40),('GITHUB_EVENT_NAME','push')]:
            with self.subTest(key=key):
                env={**self.env,key:value}
                self.rejected(lambda:r.execution_guard('deploy',env,self.api))
    def test_main_drift(self):
        self.api.main='0'*40
        self.rejected(lambda:r.execution_guard('deploy',self.env,self.api))
    def test_api_wrong_source(self):
        for key,value in [('path','old.yml'),('head_sha','0'*40),('run_attempt',2),('event','push'),('head_branch','other')]:
            with self.subTest(key=key),patch.dict(self.run,{key:value}):self.rejected(lambda:r.run_metadata(self.api,500,SHA))
    def test_environment_main_only(self):
        r.environment_guard(self.api,True,self.env)
        self.api.rules['branch_policies'][0]['name']='*'
        self.rejected(lambda:r.environment_guard(self.api,True,self.env))
    def test_admin_bypass_forbidden(self):
        self.api.env['can_admins_bypass']=True
        self.rejected(lambda:r.environment_guard(self.api,True,self.env))
    def test_bad_cookie(self):
        for value in ['', 'Cookie: hgo_admin=x','hgo_admin=x;','hgo_admin='+'x'*32+'\n']:
            self.rejected(lambda:r.environment_guard(self.api,True,{**self.env,'ALLEGRO_SMOKE_ADMIN_COOKIE':value}))
    def test_exact_off(self):self.assertEqual(r.validate_rows(r.read_vector(self.remote)[0]),'OFF')
    def test_exact_target(self):self.assertEqual(r.validate_rows(r.read_vector(Remote(self.ready))[0]),'READY')
    def test_each_bit_drift(self):
        for path in r.core.ALLOWLIST:
            with self.subTest(path=path):
                remote=Remote({**self.off,path:bytes([self.off[path][0]^1])+self.off[path][1:]})
                with self.assertRaisesRegex(r.Failure,'MANUAL'):r.validate_rows(r.read_vector(remote)[0])
                self.assertFalse(remote.stores())
    def test_each_missing(self):
        for path in r.core.ALLOWLIST:
            with self.subTest(path=path):
                remote=Remote(self.off);del remote.files[path]
                with self.assertRaisesRegex(r.Failure,'MANUAL'):r.validate_rows(r.read_vector(remote)[0])
    def test_mixed_vector_rejected(self):
        # Current profiles differ ONLY in config, so mixing their bytes equals OFF or READY.
        # Exercise vector policy with a hypothetical second changed target, never permit it.
        rows=r.read_vector(self.remote)[0]
        target=dict(self.target_hash);target['admin/index.php']='a'*64
        with patch.object(r,'vectors',return_value=(self.rows,self.off_hash,target,self.ready)):
            rows=[r.classify(p,True,b) for p,b in self.ready.items()]
            with self.assertRaisesRegex(r.Failure,'MANUAL'):r.validate_rows(rows)
    def test_partial_prefix_empty_unknown(self):
        for body in [self.ready[r.TARGET][:100],self.ready[r.TARGET][:-1],b'',b'unknown']:
            with self.subTest(length=len(body)):
                remote=Remote({**self.ready,r.TARGET:body})
                with self.assertRaisesRegex(r.Failure,'MANUAL'):r.validate_rows(r.read_vector(remote)[0])
                self.assertFalse(remote.stores())
    def test_backup_all_eleven(self):
        self.assertTrue(r.backup(self.release,self.backup,self.remote,self.run))
        files={p.relative_to(self.backup).as_posix():p.read_bytes() for p in self.backup.rglob('*') if p.is_file()}
        r.validate_backup(files,self.run)
        self.assertEqual(len(files),13)
        self.assertFalse(self.remote.stores())
    def test_target_backup_noop(self):
        self.assertFalse(r.backup(self.release,self.backup,Remote(self.ready),self.run))
        self.assertFalse(self.backup.exists())
    def test_uploaded_backup_and_intent_before_stor(self):
        self.evidence()
        self.assertEqual([x[1] for x in self.api.trace if x[0]=='uploaded'],[r.BACKUP_ARTIFACT,r.INTENT_ARTIFACT])
        self.assertFalse(self.remote.stores())
        r.deploy(self.release,self.remote,self.run,self.api,self.env)
        self.assertEqual(self.remote.stores(),[('STOR',r.TARGET)])
        self.assertEqual(self.remote.files,self.ready)
    def test_missing_uploaded_backup(self):self.rejected(lambda:r.make_intent(self.api,self.run,1,'0'*64))
    def test_missing_uploaded_intent(self):self.rejected(lambda:r.deploy(self.release,self.remote,self.run,self.api,self.env))
    def test_concurrent_change_after_backup(self):
        self.evidence();self.remote.files['admin/index.php']=b'drift'
        self.rejected(lambda:r.deploy(self.release,self.remote,self.run,self.api,self.env))
    def test_immediate_pre_stor_drift(self):
        self.evidence();count=0
        def hook(remote,path):
            nonlocal count
            if path==r.TARGET:
                count+=1
                if count==2:remote.files[path]=b'drift'
        self.remote.hook=hook
        self.rejected(lambda:r.deploy(self.release,self.remote,self.run,self.api,self.env))
    def test_interrupted_write_manual_only(self):
        self.evidence();self.remote.interrupt=True
        with self.assertRaises(OSError):r.deploy(self.release,self.remote,self.run,self.api,self.env)
        with self.assertRaisesRegex(r.Failure,'MANUAL'):r.recover(self.remote,self.run,self.api)
        self.assertEqual(len(self.remote.stores()),1)
    def test_bad_readback(self):
        self.evidence();self.remote.bad_readback=True
        with self.assertRaisesRegex(r.Failure,'READBACK'):r.deploy(self.release,self.remote,self.run,self.api,self.env)
    def test_recovery_target_restores_only_config(self):
        self.evidence();self.remote.files=dict(self.ready)
        self.assertTrue(r.recover(self.remote,self.run,self.api))
        self.assertEqual(self.remote.files,self.off)
        self.assertEqual(self.remote.stores(),[('STOR',r.TARGET)])
    def test_recovery_off_noop(self):
        self.evidence();self.assertFalse(r.recover(self.remote,self.run,self.api));self.assertFalse(self.remote.stores())
    def test_recovery_partial_no_stor(self):
        self.evidence()
        for body in [b'',b'<?php',self.ready[r.TARGET][:-1],b'unknown']:
            self.remote.files={**self.ready,r.TARGET:body}
            self.rejected(lambda:r.recover(self.remote,self.run,self.api))
    def test_recovery_other_ten_drift(self):
        self.evidence()
        for path in set(self.off)-{r.TARGET}:
            self.remote.files={**self.ready,path:b'drift'}
            self.rejected(lambda:r.recover(self.remote,self.run,self.api))
    def test_wrong_source_backup(self):
        files,_=self.evidence()
        for key,value in [('source_run_id',501),('source_commit_sha','0'*40),('workflow_path','other'),('production_job','qualify'),('run_attempt',2)]:
            with self.subTest(key=key):
                changed=copy.deepcopy(files);state=json.loads(changed['state.json']);state[key]=value;changed['state.json']=json.dumps(state).encode()
                self.rejected(lambda:r.validate_backup(changed,self.run))
    def test_wrong_artifact_id(self):
        self.evidence();self.env['DR_INTENT_ARTIFACT_ID']='999'
        self.rejected(lambda:r.deploy(self.release,self.remote,self.run,self.api,self.env))
    def test_wrong_artifact_digest(self):
        self.evidence();self.env['DR_INTENT_ARTIFACT_SHA256']='0'*64
        self.rejected(lambda:r.deploy(self.release,self.remote,self.run,self.api,self.env))
    def test_download_digest_mismatch(self):
        self.evidence();self.api.blobs[2]+=b'corruption'
        self.rejected(lambda:r.recovery_evidence(self.api,self.run))
    def test_corrupt_zip(self):self.rejected(lambda:r.archive(b'not zip',{'intent.json'}))
    def test_archive_extra_and_duplicate(self):
        self.rejected(lambda:r.archive(zipped({'intent.json':b'{}','private_allegro/config.php':b'fake'}),{'intent.json'}))
        stream=io.BytesIO()
        with zipfile.ZipFile(stream,'w') as z:
            z.writestr('x',b'1');z.writestr('x',b'2')
        self.rejected(lambda:r.archive(stream.getvalue(),{'x'}))
    def test_wrong_manifest(self):
        files,_=self.evidence();files['release-manifest.json']=b'{}'
        self.rejected(lambda:r.validate_backup(files,self.run))
    def test_each_backup_hash(self):
        files,_=self.evidence()
        for path in r.core.ALLOWLIST:
            changed={**files,'files/'+path:b'drift'}
            self.rejected(lambda:r.validate_backup(changed,self.run))
    def test_historical_backups(self):
        for number in [16,20]:
            with patch.dict(self.run,{'run_number':number}):self.rejected(lambda:r.recovery_evidence(self.api,self.run))
    def test_wrong_artifact_owner(self):
        self.evidence();self.api.artifacts[500][1]['workflow_run']['id']=501
        self.rejected(lambda:r.recovery_evidence(self.api,self.run))
    def test_duplicate_artifact(self):
        self.evidence();self.api.artifacts[500].append(self.api.artifacts[500][1])
        self.rejected(lambda:r.recovery_evidence(self.api,self.run))
    def test_expired_artifact(self):
        self.evidence();self.api.artifacts[500][1]['expired']=True
        self.rejected(lambda:r.recovery_evidence(self.api,self.run))
    def test_qualification_artifact(self):
        run=self.api.add_run(400,39);self.api.upload(run,r.RELEASE_ARTIFACT,self.release_files)
        r.qualified_source(self.api,400,SHA)
    def test_qualification_production_not_skipped(self):
        run=self.api.add_run(400,39);self.api.jobs[400][-2]['conclusion']='success'
        self.rejected(lambda:r.qualified_source(self.api,400,SHA))
    def test_private_never_read(self):
        self.evidence();r.deploy(self.release,self.remote,self.run,self.api,self.env)
        self.assertTrue(all(path in r.core.ALLOWLIST and 'private_allegro' not in path for _,path in self.remote.trace))
    def test_no_secrets_in_metadata(self):
        files,intent=self.evidence()
        metadata=json.dumps(intent)+files['state.json'].decode()
        self.assertNotIn(self.env['ALLEGRO_SMOKE_ADMIN_COOKIE'],metadata)
        self.assertNotIn('ALLEGRO_FTP_PASSWORD',metadata)
        self.assertNotIn('client_secret',metadata)
    def panel(self):
        return '<dl>'+''.join('<dt>'+k+'</dt><dd>'+v+'</dd>' for k,v in s.EXPECTED.items())+'<dt>Konfiguracja OAuth</dt><dd>Niekompletna lub zablokowana</dd></dl>'
    def headers(self):
        h=Message();h['Cache-Control']='no-store';h['Referrer-Policy']='no-referrer';h['Content-Security-Policy']="default-src 'none'; frame-ancestors 'none'";return h
    def smoke(self,body=None,status=200,headers=None):return s.evaluate('/admin/allegro/',status,headers or self.headers(),self.panel() if body is None else body,True)
    def test_smoke_off_pass(self):self.assertEqual(self.smoke()['result'],'PASS')
    def test_smoke_connect(self):
        for suffix in ['<input name="action" value="connect">','<button>Połącz z Allegro</button>','<a href="?action=connect">X</a>']:
            self.assertEqual(self.smoke(self.panel()+suffix)['result'],'FAIL')
    def test_smoke_oauth_on(self):self.assertEqual(self.smoke(self.panel().replace('Wyłączony','Włączony'))['result'],'FAIL')
    def test_smoke_connected(self):self.assertEqual(self.smoke(self.panel().replace('Nie połączono','Połączono'))['result'],'FAIL')
    def test_smoke_storage_unavailable(self):self.assertEqual(self.smoke(self.panel().replace('Dostępny','Niedostępny'))['result'],'FAIL')
    def test_smoke_wrong_environment(self):self.assertEqual(self.smoke(self.panel().replace('PRODUKCJA','SANDBOX'))['result'],'FAIL')
    def test_smoke_bad_session(self):
        self.assertEqual(self.smoke('<form>login</form>')['result'],'FAIL')
        h=self.headers();h['Location']='/admin/'
        self.assertEqual(self.smoke(status=302,headers=h)['result'],'FAIL')
    def test_smoke_ambiguous(self):
        for body in [self.panel()+self.panel(),self.panel().replace('</dd>','',1),self.panel().replace('<dt>Status</dt><dd>Nie połączono</dd>','')]:
            self.assertEqual(self.smoke(body)['result'],'FAIL')
    def test_smoke_report_sanitized(self):
        report=json.dumps(self.smoke(self.panel()+'<p>SYNTHETIC_ACCOUNT_SECRET</p>'))
        self.assertNotIn('SYNTHETIC_ACCOUNT_SECRET',report)
        self.assertEqual(set(json.loads(report)),{'http_status','checks','result'})
    def test_manifest_profile_and_payload(self):
        r.verify_ready(self.release,SHA)
        self.assertEqual([p for p in self.off if self.off[p]!=self.ready[p]],[r.TARGET])
        config=self.ready[r.TARGET].decode()
        self.assertIn('allegro:api:profile:read',config)
    def test_no_real_transports(self):
        with patch('urllib.request.OpenerDirector.open',side_effect=AssertionError('NETWORK_FORBIDDEN')):
            r.verify_ready(self.release,SHA)
            self.evidence()
            r.deploy(self.release,self.remote,self.run,self.api,self.env)
            self.assertEqual(self.smoke()['result'],'PASS')
    def test_old_helper_workflow_unchanged(self):
        for path in ['scripts/allegro-infra-release.py','.github/workflows/deploy-allegro-infra-getspace.yml']:
            self.assertEqual((ROOT/path).read_bytes().replace(b'\r\n',b'\n'),subprocess.check_output(['git','show','HEAD:'+path],cwd=ROOT).replace(b'\r\n',b'\n'))
            subprocess.run(['git','diff','--exit-code','HEAD','--',path],cwd=ROOT,check=True)
    def test_workflow_structure(self):
        import yaml
        doc=yaml.load((ROOT/r.WORKFLOW).read_text(encoding='utf-8-sig'),Loader=yaml.BaseLoader)
        self.assertEqual(list(doc['on']),['workflow_dispatch'])
        inputs=doc['on']['workflow_dispatch']['inputs']
        self.assertEqual(inputs['mode']['default'],'qualify-only')
        self.assertEqual(doc['concurrency'],{'group':'getspace-production','cancel-in-progress':'false'})
        self.assertEqual(doc['permissions'],{'contents':'read','actions':'read'})
        self.assertEqual(set(doc['jobs']),{'qualify','build','verify','inspect','production','recovery'})
        text=(ROOT/r.WORKFLOW).read_text()
        self.assertIn('--profile DEVICE_READY_CONFIG_OFF',text)
        for job in ['inspect','production','recovery']:
            self.assertEqual(doc['jobs'][job]['environment'],'allegro-infra-production')
        for job in ['qualify','build','verify']:self.assertNotIn('environment',doc['jobs'][job])
    def test_no_remote_prefix_heuristic(self):
        import inspect
        source=inspect.getsource(r.recover)
        self.assertNotIn('startswith',source)
        self.assertNotIn('core.rollback',source)
    def test_whitespace(self):
        subprocess.run(['git','diff','--check'],cwd=ROOT,check=True)


if __name__=='__main__':
    unittest.main(verbosity=2)
