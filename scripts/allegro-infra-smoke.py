"""Future production GET-only smoke. Explicit --execute required; never follows redirects."""
import argparse
import http.cookies
import json
import os
from pathlib import Path
import re
import urllib.error
import urllib.parse
import urllib.request


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def check(ok, message):
    if not ok:
        raise ValueError(message)


def effective_headers(path, status, headers, authenticated=False):
    report = {'path': path, 'authenticated': authenticated, 'status': status,
              'headers': {k: headers.get_all(k, []) for k in ['Content-Security-Policy', 'Cache-Control', 'Referrer-Policy']},
              'location': [], 'set_cookie': []}
    for value in headers.get_all('Location', []):
        parts = urllib.parse.urlsplit(value)
        origin = ('https://mgoutlet.pl' if parts.netloc == 'mgoutlet.pl' and parts.scheme == 'https' else '[UNEXPECTED_ORIGIN]') if parts.netloc else ''
        report['location'].append(origin + parts.path + ('?[REDACTED]' if parts.query or parts.fragment else ''))
    for value in headers.get_all('Set-Cookie', []):
        jar = http.cookies.SimpleCookie()
        jar.load(value)
        if not jar:
            report['set_cookie'].append({'value': '[UNPARSEABLE_REDACTED]'})
        for name, morsel in jar.items():
            report['set_cookie'].append({'name': name, 'value': '[REDACTED]', 'secure': bool(morsel['secure']),
                                         'httponly': bool(morsel['httponly']), 'samesite': morsel['samesite']})
    return report


def evaluate(path, status, headers, body, authenticated=False):
    """Pure response checks, exercised with local synthetic responses."""
    report = effective_headers(path, status, headers, authenticated)
    locations = headers.get_all('Location', [])
    cookies = headers.get_all('Set-Cookie', [])
    for value in locations:
        parts = urllib.parse.urlsplit(value)
        check(not parts.netloc or (parts.scheme == 'https' and parts.netloc == 'mgoutlet.pl'), 'Unexpected redirect host')
    for value in cookies:
        jar = http.cookies.SimpleCookie()
        jar.load(value)
        check(bool(jar), 'Unparseable cookie')
        for name, morsel in jar.items():
            if name == 'hgo_admin':
                check(morsel['secure'] and morsel['httponly'] and morsel['samesite'].lower() == 'strict', 'Unsafe admin cookie')
    if path.startswith('/admin/allegro/') and path.endswith(('/', 'callback', 'callback.php')):
        check(any('no-store' in v.lower() for v in headers.get_all('Cache-Control', [])), 'No-store missing')
        check(headers.get_all('Referrer-Policy', []) == ['no-referrer'], 'Conflicting/missing Referrer-Policy')
        csp = headers.get_all('Content-Security-Policy', [])
        check(bool(csp) and all('default-src' in v and "frame-ancestors 'none'" in v for v in csp), 'CSP missing/incomplete')
        # Multiple effective CSP headers can intersect unexpectedly: require operator resolution.
        check(len(csp) == 1, 'Multiple effective CSP policies require review')
    if path in ['/admin/allegro/callback', '/admin/allegro/callback.php']:
        check(status == 400 and not locations and not cookies and 'Nie można potwierdzić' in body, 'Invalid callback unsafe')
        check("default-src 'none'" in headers.get('Content-Security-Policy', ''), 'Callback CSP unsafe')
    elif path == '/admin/allegro/' and authenticated:
        check(status in (200, 400) and 'Konfiguracja OAuth' in body and 'Niekompletna lub zablokowana' in body,
              'Authenticated safe status unavailable')
        check('name="action" value="connect"' not in body and 'data-allegro-finish' not in body
              and 'OAuth w konfiguracji</dt><dd>Wyłączony' in body, 'OAuth enabled in released panel')
    elif path in ['/admin/allegro/', '/admin/olx/']:
        check(status in (302, 303) and len(locations) == 1 and urllib.parse.urlsplit(locations[0]).path == '/admin/', 'Anonymous login gate failed')
    elif path.startswith('/admin/allegro/') or path == '/admin/olx/lib.php':
        check(status in (403, 404) and '<?php' not in body, 'Private library exposed')
    else:
        check(status == 200, 'Public site smoke failed')
    return report


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--execute', action='store_true')
    parser.add_argument('--report', type=Path, required=True)
    args = parser.parse_args()
    check(args.execute, 'Explicit execution required')
    cookie = os.environ.get('ALLEGRO_SMOKE_ADMIN_COOKIE', '')
    check(re.fullmatch(r'hgo_admin=[A-Za-z0-9,-]{16,256}', cookie) is not None, 'Authenticated session required')
    paths = [('/admin/allegro/', False), ('/admin/allegro/', True),
             ('/admin/allegro/callback', False), ('/admin/allegro/callback.php', False),
             *[('/admin/allegro/' + name + '.php', False) for name in ['lib', 'config', 'storage', 'http', 'oauth']],
             ('/admin/olx/', False), ('/admin/olx/lib.php', False), ('/', False), ('/dom', False), ('/ogrod', False)]
    opener = urllib.request.build_opener(NoRedirect())
    reports, failures = [], 0
    for path, auth in paths:
        response = None
        req = urllib.request.Request('https://mgoutlet.pl' + path, method='GET', headers={
            'User-Agent': 'HGO Allegro infrastructure OAuth-OFF smoke', **({'Cookie': cookie} if auth else {})})
        try:
            try:
                response = opener.open(req, timeout=20)
            except urllib.error.HTTPError as exc:
                response = exc
            with response:
                body = response.read(1024 * 1024 + 1)
                check(len(body) <= 1024 * 1024, 'Response too large')
                # Only selected headers are persisted; no body/cookie value or query.
                result = evaluate(path, response.code, response.headers, body.decode('utf-8', 'replace'), auth)
                result['result'] = 'PASS'
                reports.append(result)
        except Exception:
            failures += 1
            # Record safe effective headers even when a semantic check fails.
            headers = getattr(response, 'headers', None)
            try:
                result = effective_headers(path, response.code, headers, auth) if headers else {'path': path, 'authenticated': auth, 'status': None}
            except Exception:
                result = {'path': path, 'authenticated': auth, 'status': getattr(response, 'code', None), 'headers': '[UNPARSEABLE_REDACTED]'}
            reports.append({**result, 'result': 'FAIL'})
    args.report.parent.mkdir(parents=True, exist_ok=True)
    args.report.write_text(json.dumps(reports, indent=2, ensure_ascii=False), encoding='utf-8')
    check(failures == 0, 'Smoke failed; inspect sanitized report and roll back allowlisted code')
    print('PASS: GET-only Allegro infrastructure smoke')


if __name__ == '__main__':
    try:
        main()
    except Exception:
        raise SystemExit('FAIL: smoke stopped; no raw network/session output')
