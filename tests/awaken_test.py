"""Offline checks for awaken/awaken.py: no network beyond 127.0.0.1, no real credentials.

  python3 tests/awaken_test.py
"""

import http.server, os, sys, tempfile, threading

sys.dont_write_bytecode = True

os.environ['IC_HOME'] = tempfile.mkdtemp()
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'awaken'))
import awaken  # noqa: E402

failures = []


def check(name, ok):
    print(('PASS ' if ok else 'FAIL ') + name)
    if not ok:
        failures.append(name)


def serve(handler):
    srv = http.server.HTTPServer(('127.0.0.1', 0), handler)
    threading.Thread(target=srv.serve_forever, daemon=True).start()
    return srv, f'http://127.0.0.1:{srv.server_address[1]}'


# A redirect is returned as its status, and the bearer token never reaches the other host.
seen = []


class Catcher(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        seen.append(self.headers.get('Authorization'))
        self.send_response(200); self.end_headers(); self.wfile.write(b'{}')

    def log_message(self, *a):
        pass


catcher, catcher_url = serve(Catcher)


class Redirector(Catcher):
    def do_POST(self):
        self.send_response(307); self.send_header('Location', catcher_url + '/stolen'); self.end_headers()


redirector, redirector_url = serve(Redirector)
status, _ = awaken.http(redirector_url, {'x': 1}, headers={'Authorization': 'Bearer secret'})
check('redirect is not followed', status == 307)
check('token never sent to the redirect target', seen == [])

# An unreachable city is status 0, not a crash.
redirector.shutdown(); redirector.server_close()
status, _ = awaken.http(redirector_url, {'x': 1})
check('unreachable city answers status 0', status == 0)

# A job the agent cannot finish wakes it once, not on every check.
runs = []
awaken.pending = lambda agent: '|id|state|\n|abc|queued|'
awaken.wake = lambda agent, run, jobs, timeout: runs.append(jobs)
awaken.check_and_wake('grok', 'true', 60)
awaken.check_and_wake('grok', 'true', 60)
check('stuck job wakes the agent once per STUCK window', len(runs) == 1)
awaken.LAST['at'] -= awaken.STUCK
awaken.check_and_wake('grok', 'true', 60)
check('stuck job is retried after the window', len(runs) == 2)

# A new job alongside the stuck one wakes the agent at once.
awaken.pending = lambda agent: '|id|state|\n|abc|queued|\n|def|queued|'
awaken.check_and_wake('grok', 'true', 60)
check('a changed queue wakes the agent again', len(runs) == 3)

# Renewal: a temporary failure is retried, a refused refresh token ends the connection.
awaken.save('grok', {'client_id': 'c', 'access': 'a', 'refresh': 'r', 'exp': 0})
real_http = awaken.http
awaken.http = lambda *a, **k: (503, 'busy')
try:
    awaken.token('grok'); check('503 on renewal raises a retryable error', False)
except RuntimeError:
    check('503 on renewal raises a retryable error', True)
awaken.http = lambda *a, **k: (400, {'error': 'invalid_grant'})
try:
    awaken.token('grok'); check('400 on renewal ends the connection', False)
except SystemExit:
    check('400 on renewal ends the connection', True)
awaken.http = lambda *a, **k: (200, {'access_token': 'new', 'expires_in': 3600})
check('renewal without a rotated refresh token keeps the old one',
      awaken.token('grok') == 'new' and awaken.load('grok')['refresh'] == 'r')
awaken.http = real_http

if os.name == 'posix':
    check('credentials file is owner-only', oct(os.stat(awaken.cred_path('grok')).st_mode & 0o777) == '0o600')

catcher.shutdown()
print(f'{"FAIL" if failures else "PASS"}: awakener offline checks')
sys.exit(1 if failures else 0)
