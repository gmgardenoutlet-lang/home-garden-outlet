"""GET-only smoke; panel GET may maintain encrypted vault/session locks, never private config."""
import argparse
from html.parser import HTMLParser
import importlib.util
import json
import os
from pathlib import Path
import re
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('off_smoke', ROOT / 'scripts/allegro-infra-smoke.py')
off = importlib.util.module_from_spec(spec)
spec.loader.exec_module(off)
EXPECTED = {'Środowisko': 'PRODUKCJA', 'Prywatny magazyn': 'Dostępny',
            'OAuth w konfiguracji': 'Wyłączony', 'Status': 'Nie połączono'}
PATHS = [('/admin/allegro/', False), ('/admin/allegro/', True),
         ('/admin/allegro/callback', False), ('/admin/allegro/callback.php', False),
         *[('/admin/allegro/' + n + '.php', False) for n in ['lib', 'config', 'storage', 'http', 'oauth']],
         ('/admin/olx/', False), ('/admin/olx/lib.php', False), ('/', False), ('/dom', False), ('/ogrod', False)]


class Panel(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.fields, self.tag, self.parts, self.label = {}, None, [], None
        self.invalid = False
        self.connect = False
        self.text = []

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if attrs.get('name') == 'action' and attrs.get('value') == 'connect':
            self.connect = True
        if 'action=connect' in attrs.get('href', ''):
            self.connect = True
        if tag in ('dt', 'dd'):
            if self.tag is not None or (tag == 'dd' and self.label is None):
                self.invalid = True
            self.tag, self.parts = tag, []

    def handle_data(self, data):
        self.text.append(data)
        if self.tag:
            self.parts.append(data)

    def handle_endtag(self, tag):
        if tag not in ('dt', 'dd'):
            return
        if self.tag != tag:
            self.invalid = True
            return
        value = ' '.join(''.join(self.parts).split())
        if tag == 'dt':
            if self.label is not None:
                self.invalid = True
            self.label = value
        else:
            if self.label in self.fields:
                self.invalid = True
            self.fields[self.label] = value
            self.label = None
        self.tag, self.parts = None, []

    def valid(self):
        return (not self.invalid and self.tag is None and self.label is None and not self.connect
                and 'Połącz z Allegro' not in ' '.join(self.text)
                and all(self.fields.get(k) == v for k, v in EXPECTED.items()))


def evaluate(path, status, headers, body, authenticated=False):
    checks = {'baseline': False}
    try:
        off.evaluate(path, status, headers, body, authenticated)
        checks['baseline'] = True
    except Exception:
        pass
    if path == '/admin/allegro/' and authenticated:
        panel = Panel()
        try:
            panel.feed(body)
            panel.close()
            valid = panel.valid()
        except Exception:
            valid = False
        checks.update(http_200=status == 200, no_redirect=not headers.get_all('Location', []), panel_off=valid)
    return {'http_status': status, 'checks': checks, 'result': 'PASS' if all(checks.values()) else 'FAIL'}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--execute', action='store_true')
    parser.add_argument('--report', type=Path, required=True)
    parser.add_argument('--state', choices=['device-ready', 'immutable-off'], default='device-ready')
    args = parser.parse_args()
    cookie = os.environ.get('ALLEGRO_SMOKE_ADMIN_COOKIE', '')
    if not args.execute or re.fullmatch(r'hgo_admin=[A-Za-z0-9,-]{16,256}', cookie) is None:
        raise ValueError('EXECUTION_REJECTED')
    opener = urllib.request.build_opener(off.NoRedirect())
    reports = []
    for path, auth in PATHS:
        status = None
        try:
            request = urllib.request.Request('https://mgoutlet.pl' + path, method='GET',
                headers={'User-Agent': 'HGO Device Ready infrastructure smoke', **({'Cookie': cookie} if auth else {})})
            try:
                response = opener.open(request, timeout=20)
            except urllib.error.HTTPError as exc:
                response = exc
            with response:
                status = response.code
                body = response.read(1024 * 1024 + 1)
                if len(body) > 1024 * 1024:
                    raise ValueError('RESPONSE_LIMIT')
                reports.append(evaluate(path, status, response.headers, body.decode('utf-8', 'strict'), auth))
        except Exception:
            reports.append({'http_status': status, 'checks': {'response_valid': False}, 'result': 'FAIL'})
    args.report.parent.mkdir(parents=True, exist_ok=True)
    args.report.write_text(json.dumps(reports, indent=2) + '\n', encoding='utf-8')
    if any(r['result'] != 'PASS' for r in reports):
        raise ValueError('SMOKE_FAILED')


if __name__ == '__main__':
    try:
        main()
        print('PASS: Device Ready GET smoke')
    except Exception:
        raise SystemExit('FAIL: Device Ready smoke')
