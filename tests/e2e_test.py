"""End-to-end test of the Visual Realm plugin against the local test site."""
import base64, hashlib, json, re, secrets, sys, urllib.parse
import requests

BASE = "http://localhost:8890"
MCP = BASE + "/wp-json/visual-realm/v1/mcp"
REDIRECT = "https://claude.ai/api/mcp/auth_callback"
results = []
TERM = "Clinical Studies " + secrets.token_hex(3)


def check(name, cond, detail=""):
    results.append((name, bool(cond)))
    print(("PASS " if cond else "FAIL ") + name + ("" if cond else f"  -> {detail}"))


def pkce():
    v = secrets.token_urlsafe(48)
    c = base64.urlsafe_b64encode(hashlib.sha256(v.encode()).digest()).rstrip(b"=").decode()
    return v, c


def login(user, pw):
    s = requests.Session()
    s.get(BASE + "/wp-login.php")
    s.post(BASE + "/wp-login.php", data={"log": user, "pwd": pw, "wp-submit": "Log In", "testcookie": "1"}, allow_redirects=False)
    return s


def authorize(sess, client_id, challenge, redirect=REDIRECT, approve=True):
    params = {"response_type": "code", "client_id": client_id, "redirect_uri": redirect,
              "code_challenge": challenge, "code_challenge_method": "S256", "state": "st123",
              "scope": "mcp", "resource": MCP}
    r = sess.get(BASE + "/vr-oauth/authorize", params=params, allow_redirects=False)
    if r.status_code != 200:
        return r, None
    nonce = re.search(r'name="_vrnonce" value="([^"]+)"', r.text)
    form = dict(params, _vrnonce=nonce.group(1) if nonce else "", decision="approve" if approve else "deny")
    r2 = sess.post(BASE + "/vr-oauth/authorize", data=form, allow_redirects=False)
    return r, r2


def rpc(token, method, params=None, id_=1):
    body = {"jsonrpc": "2.0", "id": id_, "method": method}
    if params is not None:
        body["params"] = params
    return requests.post(MCP, json=body, headers={"Authorization": "Bearer " + token,
                                                  "Accept": "application/json, text/event-stream"})


def tool(token, name, args=None):
    r = rpc(token, "tools/call", {"name": name, "arguments": args or {}})
    res = r.json()["result"]
    text = res["content"][0]["text"]
    try:
        data = json.loads(text)
    except ValueError:
        data = text
    return res["isError"], data


# --- 401 challenge -----------------------------------------------------------
r = requests.post(MCP, json={"jsonrpc": "2.0", "id": 1, "method": "ping"})
check("401 without token", r.status_code == 401, r.status_code)
import os
SG = bool(os.environ.get("SIMULATE_SITEGROUND"))
www = r.headers.get("WWW-Authenticate", "")
m = re.search(r'resource_metadata="([^"]+)"', www)
check("WWW-Authenticate carries resource_metadata", m is not None, www)
check("resource_metadata avoids root /.well-known", m and "/.well-known/" not in urllib.parse.urlparse(m.group(1)).path, www)
pr = requests.get(m.group(1))
check("resource metadata reachable from challenge", pr.status_code == 200 and pr.json().get("resource") == MCP, pr.text[:200])
issuer = pr.json()["authorization_servers"][0]

# Discover the auth server the way Claude does: root forms first, then the issuer-path OIDC form.
ip = urllib.parse.urlparse(issuer)
root = f"{ip.scheme}://{ip.netloc}"
candidates = [root + "/.well-known/oauth-authorization-server" + ip.path,
              root + "/.well-known/openid-configuration" + ip.path,
              issuer.rstrip("/") + "/.well-known/openid-configuration"]
asm = None
for url in candidates:
    resp = requests.get(url)
    if resp.status_code == 200 and resp.headers.get("Content-Type", "").startswith("application/json"):
        asm = resp.json()
        found_at = url
        break
check("authorization server metadata discoverable" + (" despite host /.well-known block" if SG else ""), asm is not None, candidates)
check("metadata issuer matches", asm and asm["issuer"] == issuer, asm)
if SG:
    check("root /.well-known really blocked in simulation", requests.get(root + "/.well-known/oauth-authorization-server").status_code == 404, "")
    check("found via issuer-path OIDC form", found_at == candidates[2], found_at)
else:
    pr2 = requests.get(BASE + "/.well-known/oauth-protected-resource/wp-json/visual-realm/v1/mcp").json()
    check("root path-specific resource metadata still served", pr2.get("resource") == MCP, pr2)
check("registration endpoint from metadata", asm and asm["registration_endpoint"] == BASE + "/vr-oauth/register", asm)

# --- DCR ------------------------------------------------------------------------
bad = requests.post(BASE + "/vr-oauth/register", json={"client_name": "Evil", "redirect_uris": ["https://evil.example/cb"]})
check("DCR rejects unknown redirect host", bad.status_code == 400, bad.text)
reg = requests.post(BASE + "/vr-oauth/register", json={"client_name": "Claude", "redirect_uris": [REDIRECT],
                                                       "token_endpoint_auth_method": "none",
                                                       "grant_types": ["authorization_code", "refresh_token"]})
check("DCR registers public client", reg.status_code == 201, reg.text)
client_id = reg.json()["client_id"]
reg2 = requests.post(BASE + "/vr-oauth/register", json={"client_name": "Claude (secret)", "redirect_uris": [REDIRECT],
                                                        "token_endpoint_auth_method": "client_secret_post"})
check("DCR issues secret for confidential client", "client_secret" in reg2.json(), reg2.text)

# --- Authorize -----------------------------------------------------------------
anon = requests.Session()
r = anon.get(BASE + "/vr-oauth/authorize", params={"response_type": "code", "client_id": client_id, "redirect_uri": REDIRECT,
                                                   "code_challenge": "x" * 43, "code_challenge_method": "S256"}, allow_redirects=False)
check("logged-out user sent to login", r.status_code == 302 and "wp-login.php" in r.headers.get("Location", ""), r.headers)

author = login("author1", "author-test-pass")
v, c = pkce()
page, _ = authorize(author, client_id, c)
check("non-admin cannot connect", page.status_code == 400 and "allowed to connect" in page.text, page.status_code)

admin = login("admin", "admin-test-pass")
page, _ = authorize(admin, client_id, c, redirect="https://evil.example/cb")
check("authorize rejects unregistered redirect", page.status_code == 400, page.status_code)

r = admin.get(BASE + "/vr-oauth/authorize", params={"response_type": "code", "client_id": client_id, "redirect_uri": REDIRECT,
                                                    "code_challenge": c, "code_challenge_method": "plain", "state": "s"}, allow_redirects=False)
check("plain PKCE refused", r.status_code == 302 and "error=invalid_request" in r.headers["Location"], r.headers.get("Location"))

page, dec = authorize(admin, client_id, c, approve=False)
check("deny returns access_denied", dec is not None and "error=access_denied" in dec.headers.get("Location", ""), dec and dec.headers)

v, c = pkce()
page, dec = authorize(admin, client_id, c)
check("consent page shown", page.status_code == 200 and "Allow Claude to manage" in page.text, page.text[:300])
loc = dec.headers.get("Location", "")
q = urllib.parse.parse_qs(urllib.parse.urlparse(loc).query)
check("approve redirects with code, state and iss", loc.startswith(REDIRECT) and "code" in q and q.get("state") == ["st123"] and q.get("iss") == [issuer], loc)
code = q["code"][0]

# --- Token ---------------------------------------------------------------------
t = requests.post(BASE + "/vr-oauth/token", data={"grant_type": "authorization_code", "code": code, "client_id": client_id,
                                                   "redirect_uri": REDIRECT, "code_verifier": "wrong" * 10})
check("wrong PKCE verifier rejected", t.status_code == 400 and t.json()["error"] == "invalid_grant", t.text)

v, c = pkce()
_, dec = authorize(admin, client_id, c)
code = urllib.parse.parse_qs(urllib.parse.urlparse(dec.headers["Location"]).query)["code"][0]
t = requests.post(BASE + "/vr-oauth/token", data={"grant_type": "authorization_code", "code": code, "client_id": client_id,
                                                   "redirect_uri": REDIRECT, "code_verifier": v})
check("code exchanged for tokens", t.status_code == 200 and t.json().get("token_type") == "Bearer", t.text)
check("token response not cacheable", "no-store" in t.headers.get("Cache-Control", ""), t.headers)
tok = t.json()
again = requests.post(BASE + "/vr-oauth/token", data={"grant_type": "authorization_code", "code": code, "client_id": client_id,
                                                       "redirect_uri": REDIRECT, "code_verifier": v})
check("code is single-use", again.status_code == 400, again.text)

# confidential client must authenticate
sec = reg2.json()
v2, c2 = pkce()
_, dec2 = authorize(admin, sec["client_id"], c2)
code2 = urllib.parse.parse_qs(urllib.parse.urlparse(dec2.headers["Location"]).query)["code"][0]
nos = requests.post(BASE + "/vr-oauth/token", data={"grant_type": "authorization_code", "code": code2, "client_id": sec["client_id"],
                                                     "redirect_uri": REDIRECT, "code_verifier": v2, "client_secret": "nope"})
check("confidential client wrong secret rejected", nos.status_code == 401, nos.text)

AT = tok["access_token"]

# --- MCP protocol ---------------------------------------------------------------
r = rpc(AT, "initialize", {"protocolVersion": "2025-06-18", "capabilities": {}, "clientInfo": {"name": "test", "version": "1"}})
init = r.json()["result"]
check("initialize negotiates version", init["protocolVersion"] == "2025-06-18" and r.headers.get("MCP-Protocol-Version") == "2025-06-18", init)
check("tools capability advertised", "tools" in init["capabilities"], init)
n = requests.post(MCP, json={"jsonrpc": "2.0", "method": "notifications/initialized"}, headers={"Authorization": "Bearer " + AT})
check("notification gets 202 with empty body", n.status_code == 202 and n.text == "", (n.status_code, n.text))
check("ping returns {}", rpc(AT, "ping").json()["result"] == {}, rpc(AT, "ping").text)
tl = rpc(AT, "tools/list").json()["result"]["tools"]
names = {x["name"] for x in tl}
check("tools/list has core tools", {"wp_create_post", "wp_alter_post", "wp_update_seo", "mcp_undo_change", "vr_purge_cache"} <= names, names)
raw = rpc(AT, "tools/list").text
check("empty-arg tools serialize properties as {}", '"name":"mcp_ping","description":"Site, WordPress, theme and connection details.","inputSchema":{"type":"object","properties":{}}' in raw, raw[:300])
check("unknown method -32601", rpc(AT, "foo/bar").json()["error"]["code"] == -32601, "")
check("GET returns 405", requests.get(MCP, headers={"Authorization": "Bearer " + AT}).status_code == 405, "")
batch = requests.post(MCP, json=[{"jsonrpc": "2.0", "id": 1, "method": "ping"}, {"jsonrpc": "2.0", "id": 2, "method": "tools/list"}],
                      headers={"Authorization": "Bearer " + AT}).json()
check("batch request answered", isinstance(batch, list) and len(batch) == 2, batch)

# --- Tools ---------------------------------------------------------------------
err, ping = tool(AT, "mcp_ping")
check("mcp_ping", not err and ping["user"] == "admin", ping)

content1 = '[fusion_builder_container]<p>Intro text</p>[/fusion_builder_container]'
schema_str = '{"@context":"https:\\/\\/schema.org","name":"O\'Brien \\"Quote\\" \\\\ test"}'
err, made = tool(AT, "wp_create_post", {"post_type": "page", "title": "Weight Loss Study", "content": content1,
                                        "meta": {"vr_test_json": schema_str}})
check("create defaults to draft", not err and made["status"] == "draft", made)
pid = made["id"]
err, got = tool(AT, "wp_get_post_meta", {"id": pid, "key": "vr_test_json"})
check("meta with backslashes/quotes stored exactly", got["value"] == schema_str, got)

err, ap = tool(AT, "wp_append_content", {"id": pid, "content": '[fusion_builder_container]<p>Part two \\o/</p>[/fusion_builder_container]'})
err, post = tool(AT, "wp_get_post", {"id": pid})
check("append keeps backslashes", post["content"].endswith('<p>Part two \\o/</p>[/fusion_builder_container]'), post["content"][-80:])

err, up = tool(AT, "wp_update_post", {"id": pid, "title": "Weight Loss Clinical Study"})
err, post = tool(AT, "wp_get_post", {"id": pid, "include_content": False})
check("update_post leaves draft as draft", post["status"] == "draft" and post["title"] == "Weight Loss Clinical Study", post)

err, msg = tool(AT, "wp_alter_post", {"id": pid, "search": "[fusion_builder_container]", "replace": "X"})
check("alter refuses ambiguous match", err and "Found 2 matches" in msg, msg)
err, alt = tool(AT, "wp_alter_post", {"id": pid, "search": "Intro text", "replace": "Intro copy"})
check("alter replaces exact single match", not err and alt["replaced"] == 1, alt)

err, sl = tool(AT, "wp_get_post", {"id": pid, "offset": 10, "limit": 20})
check("content slicing", sl["content_more"] and sl["next_offset"] == 30 and len(sl["content"]) == 20, sl)

schema_obj = {"metadata": {"type": "custom", "title": "MedicalStudy"}, "@type": "MedicalStudy", "name": "Weight Loss"}
err, mm = tool(AT, "wp_update_post_meta", {"id": pid, "key": "rank_math_schema_MedicalStudy", "value": schema_obj})
check("structured meta round-trips", mm["stored"], mm)
err, seo = tool(AT, "wp_update_seo", {"id": pid, "title": "Weight Loss Study | Test", "description": "Desc", "focus_keyword": "weight loss study"})
err, gs = tool(AT, "wp_get_seo", {"id": pid})
check("SEO fields written and read", gs["title"] == "Weight Loss Study | Test" and "rank_math_schema_MedicalStudy" in gs["schema_keys"], gs)

png = base64.b64encode(bytes.fromhex("89504e470d0a1a0a0000000d4948445200000001000000010806000000"
                                     "1f15c4890000000d4944415478da63fcffff3f0300050001ff5d25d4f60000000049454e44ae426082")).decode()
err, med = tool(AT, "wp_upload_media", {"data": png, "filename": "weight-loss-study-tampa.png", "title": "Weight loss study Tampa",
                                        "alt": "Weight loss study volunteer in Tampa", "post_id": pid})
check("media upload with SEO filename + alt", not err and med["filename"].startswith("weight-loss-study-tampa") and med["alt"].startswith("Weight loss"), med)
err, fi = tool(AT, "wp_set_featured_image", {"id": pid, "media_id": med["id"]})
check("featured image set", fi["featured_media"] == med["id"], fi)
err, um = tool(AT, "wp_update_media", {"id": med["id"], "caption": "Study volunteer"})
check("media caption updated", um["caption"] == "Study volunteer", um)

err, term = tool(AT, "wp_create_term", {"taxonomy": "category", "name": TERM})
err, pt = tool(AT, "wp_create_post", {"title": "News post"})
err, st = tool(AT, "wp_set_post_terms", {"id": pt["id"], "taxonomy": "category", "terms": [TERM]})
check("terms set by name", term["term_id"] in st["term_ids"], st)

err, posts = tool(AT, "wp_get_posts", {"post_type": "page", "search": "Weight"})
check("get_posts finds page", any(p["id"] == pid for p in posts["posts"]), posts)

err, opt = tool(AT, "wp_update_option", {"name": "siteurl", "value": "https://evil.example"})
check("protected option blocked", err, opt)
err, opt = tool(AT, "wp_get_option", {"name": "vr_settings"})
check("secret option unreadable", err, opt)
err, opt = tool(AT, "wp_update_option", {"name": "blogdescription", "value": "Testing tagline"})
check("normal option updated", not err, opt)

# publish, verify rendered, purge
err, pub = tool(AT, "wp_update_post", {"id": pid, "status": "publish"})
check("explicit publish works", pub["status"] == "publish", pub)
err, rend = tool(AT, "wp_get_rendered", {"id": pid, "count": ["Weight Loss Clinical Study"]})
check("rendered fetch + string count", not err and rend["status"] == 200 and rend["counts"][0]["count"] >= 1, rend)
err, pc = tool(AT, "vr_purge_cache", {"id": pid})
check("purge reports layers", not err and "cloudflare" in pc["purged"], pc)

# --- Undo ----------------------------------------------------------------------
err, changes = tool(AT, "mcp_list_changes", {"limit": 50})
check("change log populated", len(changes) >= 10, len(changes))
alter_change = next(c for c in changes if c["tool"] == "wp_alter_post")
err, u = tool(AT, "mcp_undo_change", {"id": alter_change["id"]})
err, post = tool(AT, "wp_get_post", {"id": pid})
check("undo alter restores text", "Intro text" in post["content"] and "Intro copy" not in post["content"], post["content"][:120])
check("undoing an old edit keeps later publish", post["status"] == "publish", post["status"])
err, u2 = tool(AT, "mcp_undo_change", {"id": alter_change["id"]})
check("double undo refused", err, u2)
opt_change = next(c for c in changes if c["tool"] == "wp_update_option")
tool(AT, "mcp_undo_change", {"id": opt_change["id"]})
err, opt = tool(AT, "wp_get_option", {"name": "blogdescription"})
check("undo option", opt["value"] != "Testing tagline", opt)
meta_change = next(c for c in changes if c["tool"] == "wp_update_post_meta")
tool(AT, "mcp_undo_change", {"id": meta_change["id"]})
err, g = tool(AT, "wp_get_post_meta", {"id": pid, "key": "rank_math_schema_MedicalStudy"})
check("undo meta removes new key", g["exists"] is False, g)
create_change = next(c for c in changes if c["tool"] == "wp_create_post" and c["object_id"] == str(pt["id"]))
tool(AT, "mcp_undo_change", {"id": create_change["id"]})
err, p2 = tool(AT, "wp_get_post", {"id": pt["id"], "include_content": False})
check("undo create trashes post", p2["status"] == "trash", p2)
err, d = tool(AT, "wp_delete_post", {"id": pid})
tool(AT, "mcp_undo_change", {"id": d["change_id"]})
err, p3 = tool(AT, "wp_get_post", {"id": pid, "include_content": False})
check("undo trash restores previous status", p3["status"] == "publish", p3)

# --- Refresh rotation, reuse detection, revoke ------------------------------------
r1 = requests.post(BASE + "/vr-oauth/token", data={"grant_type": "refresh_token", "refresh_token": tok["refresh_token"], "client_id": client_id})
check("refresh issues new pair", r1.status_code == 200 and r1.json()["refresh_token"] != tok["refresh_token"], r1.text)
new = r1.json()
check("new access token works", rpc(new["access_token"], "ping").status_code == 200, "")
reuse = requests.post(BASE + "/vr-oauth/token", data={"grant_type": "refresh_token", "refresh_token": tok["refresh_token"], "client_id": client_id})
check("reused refresh token rejected", reuse.status_code == 400, reuse.text)
check("reuse revokes the whole grant", rpc(new["access_token"], "ping").status_code == 401, "")

v, c = pkce()
_, dec = authorize(admin, client_id, c)
code = urllib.parse.parse_qs(urllib.parse.urlparse(dec.headers["Location"]).query)["code"][0]
fresh = requests.post(BASE + "/vr-oauth/token", data={"grant_type": "authorization_code", "code": code, "client_id": client_id,
                                                       "redirect_uri": REDIRECT, "code_verifier": v}).json()
rv = requests.post(BASE + "/vr-oauth/revoke", data={"token": fresh["refresh_token"], "client_id": client_id})
check("revoke endpoint 200", rv.status_code == 200, rv.text)
check("revoked grant's access token dead", rpc(fresh["access_token"], "ping").status_code == 401, "")

passed = sum(1 for _, ok in results if ok)
print(f"\n{passed}/{len(results)} passed")
sys.exit(0 if passed == len(results) else 1)
