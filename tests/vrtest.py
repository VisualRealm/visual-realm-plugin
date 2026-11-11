"""Shared helpers for the suites. Configured by tests/run.sh through environment variables."""
import base64, hashlib, json, os, re, secrets, subprocess, sys, urllib.parse
import requests

BASE = "http://localhost:8890"
MCP = BASE + "/wp-json/visual-realm/v1/mcp"
REDIRECT = "https://claude.ai/api/mcp/auth_callback"
ROOT = os.environ.get("VR_TEST_ROOT", "/home/claude/wptest")
SCRATCH = os.environ.get("VR_SCRATCH", "/tmp/vr-tests")
os.makedirs(SCRATCH, exist_ok=True)
WP = ROOT + "/wp"
results = []


def check(name, cond, detail=""):
    results.append((name, bool(cond)))
    print(("PASS " if cond else "FAIL ") + name + ("" if cond else f"  -> {str(detail)[:500]}"))


def finish():
    passed = sum(1 for _, ok in results if ok)
    print(f"\n{passed}/{len(results)} passed")
    sys.exit(0 if passed == len(results) else 1)


def php(code, server=None):
    """Run PHP inside WordPress on the CLI and return stdout. server: extra $_SERVER values."""
    pre = "".join("$_SERVER[%s]=%s;" % (json.dumps(k), json.dumps(v)) for k, v in (server or {}).items())
    script = "<?php $_SERVER['HTTP_HOST']='localhost:8890';$_SERVER['REQUEST_URI']='/';%srequire '%s/wp-load.php';%s" % (pre, WP, code)
    path = SCRATCH + "/run.php"
    open(path, "w").write(script)
    return subprocess.run(["php", path], capture_output=True, text=True).stdout.strip()


def pkce():
    v = secrets.token_urlsafe(48)
    return v, base64.urlsafe_b64encode(hashlib.sha256(v.encode()).digest()).rstrip(b"=").decode()


def login(user, pw):
    s = requests.Session()
    s.get(BASE + "/wp-login.php")
    s.post(BASE + "/wp-login.php", data={"log": user, "pwd": pw, "testcookie": "1"}, allow_redirects=False)
    return s


def register(name="Claude"):
    r = requests.post(BASE + "/vr-oauth/register", json={"client_name": name, "redirect_uris": [REDIRECT]})
    return r


def authorize(sess, client_id):
    """Returns (consent page response, decision response or None, verifier)."""
    v, c = pkce()
    p = {"response_type": "code", "client_id": client_id, "redirect_uri": REDIRECT, "code_challenge": c,
         "code_challenge_method": "S256", "state": "s"}
    page = sess.get(BASE + "/vr-oauth/authorize", params=p, allow_redirects=False)
    m = re.search(r'name="_vrnonce" value="([^"]+)"', page.text)
    if page.status_code != 200 or not m:
        return page, None, v
    d = sess.post(BASE + "/vr-oauth/authorize", data=dict(p, _vrnonce=m.group(1), decision="approve"), allow_redirects=False)
    return page, d, v


def connect(user="admin", pw="admin-test-pass"):
    s = login(user, pw)
    cid = register().json()["client_id"]
    page, d, v = authorize(s, cid)
    if d is None:
        return None, cid, s, page
    code = urllib.parse.parse_qs(urllib.parse.urlparse(d.headers["Location"]).query)["code"][0]
    tok = requests.post(BASE + "/vr-oauth/token", data={"grant_type": "authorization_code", "code": code,
                                                         "client_id": cid, "code_verifier": v})
    return tok, cid, s, page


def rpc(token, method, params=None):
    body = {"jsonrpc": "2.0", "id": 1, "method": method}
    if params is not None:
        body["params"] = params
    return requests.post(MCP, json=body, headers={"Authorization": "Bearer " + token})


def tool(token, name, args=None):
    r = rpc(token, "tools/call", {"name": name, "arguments": args or {}})
    if r.status_code != 200:
        return True, f"HTTP {r.status_code}"
    j = r.json()
    if "error" in j:
        return True, j["error"]["message"]
    res = j["result"]
    t = res["content"][0]["text"]
    try:
        return res["isError"], json.loads(t)
    except ValueError:
        return res["isError"], t


def settings(**kw):
    """Merge values into vr_settings directly."""
    php("$s=(array)get_option('vr_settings',array());$s=array_merge($s,json_decode(base64_decode('%s'),true));update_option('vr_settings',$s,false);"
        % base64.b64encode(json.dumps(kw).encode()).decode())


def calls():
    out = php("echo wp_json_encode(get_option('vr_test_calls', array()));")
    try:
        return json.loads(out)
    except ValueError:
        return out


def reset_calls():
    php("delete_option('vr_test_calls');")
