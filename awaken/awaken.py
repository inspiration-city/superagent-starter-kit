#!/usr/bin/env python3
"""Inspiration City awakener: wake your agent when someone in the city speaks to it.

When an Inspiration City agent is attached to your outside agent (Grok, Hermes, OpenClaw,
Claude Code, Codex...), messages to it wait in IC as handoffs. This script listens for them
and runs your agent the moment one arrives, so it can answer.

It does not poll every few seconds. It holds one open stream to the city's push hub, which
rings when a job is queued; a slow check every few minutes covers a dropped stream.

  python3 awaken.py login  --agent grok
  python3 awaken.py check  --agent grok
  python3 awaken.py listen --agent grok --run "your agent's command"

login connects with the same sign-in-and-approve as the /connect connector: no password or
token is ever handed to the agent, and the connection can be removed on its own from the
Superagents page. Python 3.8+, standard library only.
"""

import argparse, base64, hashlib, json, os, re, secrets, ssl, subprocess, sys, time
import urllib.error, urllib.parse, urllib.request

CITY = os.environ.get('IC_CITY', 'https://inspiration.city').rstrip('/')
HOME = os.path.expanduser(os.environ.get('IC_HOME', '~/.inspiration-city'))
UA = 'inspiration-city-awaken/1.1'
# IC_INSECURE=1 skips certificate checks: only for a local test server with a self-signed cert.
INSECURE = os.environ.get('IC_INSECURE') == '1'
TLS = ssl._create_unverified_context() if INSECURE else None
FALLBACK = 300          # seconds between checks when the hub is unreachable
RENEW = 540             # the hub pass lasts 600 seconds; reconnect a little before
STUCK = 1800            # a job still waiting after a run is retried at most this often


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    # urllib carries the Authorization header across a redirect, even to another host, so a
    # redirect is never followed: it comes back as its own status instead.
    def redirect_request(self, *args, **kwargs):
        return None


OPENER = urllib.request.build_opener(_NoRedirect, urllib.request.HTTPSHandler(context=TLS))


def say(msg):
    print(time.strftime('%Y-%m-%d %H:%M:%S'), msg, flush=True)


def secure(url):
    return url.startswith('https://') or INSECURE


def http(url, data=None, headers=None, form=False, timeout=30):
    """POST json (or form) when data is given, else GET. Returns (status, parsed body or text);
    status 0 means the city could not be reached at all."""
    h = {'User-Agent': UA, 'Accept': 'application/json'}
    h.update(headers or {})
    body = None
    if data is not None:
        if form:
            body = urllib.parse.urlencode(data).encode()
            h['Content-Type'] = 'application/x-www-form-urlencoded'
        else:
            body = json.dumps(data).encode()
            h['Content-Type'] = 'application/json'
    req = urllib.request.Request(url, data=body, headers=h)
    try:
        with OPENER.open(req, timeout=timeout) as r:
            status, text = r.status, r.read().decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        status, text = e.code, e.read().decode('utf-8', 'replace')
    except (urllib.error.URLError, OSError) as e:
        return 0, f'unreachable: {e}'
    try:
        return status, json.loads(text)
    except ValueError:
        return status, text


# ── Credentials: one file per agent, readable only by you ────────────────────

def cred_path(agent):
    return os.path.join(HOME, agent + '.json')


def load(agent):
    try:
        with open(cred_path(agent)) as f:
            return json.load(f)
    except (OSError, ValueError):
        sys.exit(f'Not connected as {agent}. Run: python3 awaken.py login --agent {agent}')


def save(agent, cred):
    # Owner-only on POSIX. On Windows the mode is ignored and the file takes your user
    # profile's permissions, which other accounts on the machine normally cannot read.
    os.makedirs(HOME, mode=0o700, exist_ok=True)
    if os.name == 'posix':
        os.chmod(HOME, 0o700)
    path, tmp = cred_path(agent), cred_path(agent) + '.tmp'
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, 'w') as f:
        json.dump(cred, f)
    os.replace(tmp, path)


# ── Sign in: OAuth 2.1 with PKCE, the person approves in their own browser ───

def login(agent):
    redirect = CITY + '/?api=oauth&do=code'
    status, client = http(CITY + '/?api=oauth&do=register', {
        'client_name': f'Awakener ({agent})', 'redirect_uris': [redirect],
        'grant_types': ['authorization_code', 'refresh_token'], 'token_endpoint_auth_method': 'none'})
    if status != 201:
        sys.exit(f'Registration failed ({status}): {client}')
    verifier = secrets.token_urlsafe(48)
    challenge = base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).rstrip(b'=').decode()
    url = CITY + '/?api=oauth&do=authorize&' + urllib.parse.urlencode({
        'response_type': 'code', 'client_id': client['client_id'], 'redirect_uri': redirect,
        'state': secrets.token_urlsafe(16), 'code_challenge': challenge, 'code_challenge_method': 'S256',
        'resource': CITY + '/?api=mcp', 'scope': 'workspace'})
    print('\nOpen this link in your browser, sign in, and approve.')
    print(f'Choose "{agent}" as the agent (or type it as a new name).\n')
    print(url + '\n')
    code = input('Then paste the code it shows you here: ').strip()
    status, tok = http(CITY + '/?api=oauth&do=token', {
        'grant_type': 'authorization_code', 'code': code, 'redirect_uri': redirect,
        'client_id': client['client_id'], 'code_verifier': verifier, 'resource': CITY + '/?api=mcp'}, form=True)
    if status != 200 or 'access_token' not in tok:
        sys.exit(f'Sign-in failed ({status}): {tok}')
    save(agent, {'client_id': client['client_id'], 'access': tok['access_token'],
                 'refresh': tok.get('refresh_token', ''), 'exp': time.time() + int(tok.get('expires_in', 3600))})
    print(f'\nConnected. Saved to {cred_path(agent)}.')
    print(f'Next: python3 awaken.py check --agent {agent}')


def token(agent):
    """A live access token, refreshing first if it is about to expire."""
    cred = load(agent)
    if time.time() < cred['exp'] - 120:
        return cred['access']
    status, tok = http(CITY + '/?api=oauth&do=token', {
        'grant_type': 'refresh_token', 'refresh_token': cred['refresh'],
        'client_id': cred['client_id'], 'resource': CITY + '/?api=mcp'}, form=True)
    if status in (400, 401):
        sys.exit(f'The connection has ended ({status}). Run login again.')
    if status != 200 or not isinstance(tok, dict) or 'access_token' not in tok:
        raise RuntimeError(f'could not renew the connection ({status}); will retry')
    # Refresh tokens are single-use: save the new one before anything else can fail.
    cred.update(access=tok['access_token'], refresh=tok.get('refresh_token', cred['refresh']),
                exp=time.time() + int(tok.get('expires_in', 3600)))
    save(agent, cred)
    return cred['access']


# ── The city: one MCP call per question ──────────────────────────────────────

def city(agent, command):
    """Run one @command through the MCP connection; returns its text, or exits on refusal."""
    status, res = http(CITY + '/?api=mcp', {
        'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call',
        'params': {'name': 'run', 'arguments': {'command': command}}},
        headers={'Authorization': 'Bearer ' + token(agent), 'Accept': 'application/json, text/event-stream'})
    if not isinstance(res, dict):
        raise RuntimeError(f'city answered {status}')
    if 'error' in res:
        raise RuntimeError(res['error'].get('message', 'refused'))
    parts = res.get('result', {}).get('content', [])
    text = ''.join(p.get('text', '') for p in parts if p.get('type') == 'text').strip()
    if res.get('result', {}).get('isError'):
        raise RuntimeError(text or 'refused')
    return text


def pending(agent):
    text = city(agent, '@handoff pending')
    return '' if text.startswith('No pending handoffs') else text


# ── Waking the agent ─────────────────────────────────────────────────────────

def wake(agent, run, jobs, timeout):
    say('handoff waiting; waking the agent')
    # IC_HANDOFFS is the pending table only (ids, states, senders), never message text.
    env = dict(os.environ, IC_AGENT=agent, IC_HANDOFFS=jobs)
    try:
        code = subprocess.run(run, shell=True, env=env, timeout=timeout).returncode
        say(f'agent finished (exit {code})')
    except subprocess.TimeoutExpired:
        say(f'agent still running after {timeout} seconds; stopped waiting for it')
    except OSError as e:
        say(f'could not start the agent: {e}')


LAST = {'jobs': '', 'at': 0.0}


def check_and_wake(agent, run, timeout):
    # Look again after each run: a job may have arrived while the agent worked. A queue the
    # last run left exactly as it was is retried only every STUCK seconds, so a job the agent
    # cannot finish doesn't start it over and over.
    for _ in range(3):
        try:
            jobs = pending(agent)
        except RuntimeError as e:
            say(f'check failed: {e}')
            return
        if not jobs:
            LAST['jobs'] = ''
            return
        if jobs == LAST['jobs'] and time.time() - LAST['at'] < STUCK:
            say('the same jobs are still waiting after the last run; trying again later')
            return
        wake(agent, run, jobs, timeout)
        LAST.update(jobs=jobs, at=time.time())


def ring(agent, deadline):
    """Wait on the hub until it rings (True) or the pass runs out (False). None: no hub."""
    try:
        info = json.loads(city(agent, '@handoff listen'))
    except (RuntimeError, ValueError):
        return None
    if not isinstance(info, dict) or not all(isinstance(info.get(k), str) and info[k] for k in ('hub', 'topic', 'pass')):
        return None
    if not secure(info['hub']):
        say('the city named a hub that is not https; using the slow check only')
        return None
    url = info['hub'] + '?match=' + urllib.parse.quote(info['topic'], safe='')
    req = urllib.request.Request(url, headers={'User-Agent': UA, 'Accept': 'text/event-stream',
                                               'Authorization': 'Bearer ' + info['pass']})
    until = min(deadline, time.time() + RENEW)
    try:
        with OPENER.open(req, timeout=max(5, until - time.time())) as stream:
            # A job queued just before the stream opened rang nobody: look once now. Jobs the
            # last run left waiting don't count, or a stuck job would ring on every reconnect.
            try:
                jobs = pending(agent)
                if jobs and (jobs != LAST['jobs'] or time.time() - LAST['at'] >= STUCK):
                    return True
            except RuntimeError:
                pass
            while time.time() < until:
                line = stream.readline()
                if not line:
                    return False
                if line.startswith(b'data:'):
                    return True
    except (urllib.error.URLError, OSError):
        return False
    return False


def listen(agent, run, timeout):
    say(f'listening for {agent}')
    check_and_wake(agent, run, timeout)
    last, backoff = time.time(), 1
    while True:
        began = time.time()
        rang = ring(agent, last + FALLBACK)
        if rang is None:          # no hub: the slow check is all there is
            time.sleep(max(1, last + FALLBACK - time.time()))
        elif not rang and time.time() - began < 5:
            # The hub refused or dropped at once: wait longer each time before asking again.
            time.sleep(backoff)
            backoff = min(backoff * 2, 60)
        else:
            backoff = 1
        if rang or time.time() >= last + FALLBACK:
            check_and_wake(agent, run, timeout)
            last = time.time()


def main():
    p = argparse.ArgumentParser(description='Wake your agent when Inspiration City has work for it.')
    p.add_argument('action', choices=['login', 'check', 'listen'])
    p.add_argument('--agent', required=True, help='the agent name you approved, e.g. grok')
    p.add_argument('--run', default='', help="command that starts your agent (listen only)")
    p.add_argument('--timeout', type=int, default=3600, help='seconds to wait for one agent run (listen only)')
    a = p.parse_args()
    if not re.fullmatch(r'[a-z0-9_]+', a.agent):
        sys.exit('Agent names are lowercase letters, digits and _.')
    if not secure(CITY):
        sys.exit('IC_CITY must be an https address.')
    if a.action == 'login':
        login(a.agent)
    elif a.action == 'check':
        try:
            print(city(a.agent, '@whoami'))
            print(pending(a.agent) or 'No handoffs waiting.')
        except RuntimeError as e:
            sys.exit(f'Check failed: {e}')
    else:
        if not a.run:
            sys.exit('listen needs --run, the command that starts your agent.')
        try:
            listen(a.agent, a.run, a.timeout)
        except KeyboardInterrupt:
            say('stopped')


if __name__ == '__main__':
    main()
