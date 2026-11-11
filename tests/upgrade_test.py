"""Upgrade in place from 0.1.3, tools, cache purge paths, disconnect, signed GitHub self-update, security regressions."""
import base64, hashlib, json, os, re, secrets, shutil, subprocess, sys, time, urllib.parse
import requests

BASE = "http://localhost:8890"
MCP = BASE + "/wp-json/visual-realm/v1/mcp"
REDIRECT = "https://claude.ai/api/mcp/auth_callback"
ROOT = os.environ.get("VR_TEST_ROOT", "/home/claude/wptest")
SRC = os.environ.get("VR_PLUGIN_SRC", os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
SCRATCH = os.environ.get("VR_SCRATCH", "/tmp/vr-tests")
OLD = os.environ.get("VR_OLD_PLUGIN", ROOT + "/old013/visual-realm")
KEY = os.environ["VR_SIGNING_KEY_FILE"]
os.makedirs(SCRATCH, exist_ok=True)
WP = ROOT + "/wp"
PLUG = WP + "/wp-content/plugins/visual-realm"
CUR = re.search(r"VR_PLUGIN_VERSION', '([0-9.]+)'", open(SRC + "/visual-realm.php").read()).group(1)


def bump(v, n=1):
    a = [int(x) for x in v.split(".")]
    a[-1] += n
    return ".".join(map(str, a))


NEXT, NEXT2 = bump(CUR), bump(CUR, 2)
results = []


def check(name, cond, detail=""):
    results.append((name, bool(cond)))
    print(("PASS " if cond else "FAIL ") + name + ("" if cond else f"  -> {str(detail)[:400]}"))


def php(code):
    """Run PHP inside WordPress on the CLI and return stdout."""
    script = "<?php $_SERVER['HTTP_HOST']='localhost:8890';$_SERVER['REQUEST_URI']='/';require '%s/wp-load.php';%s" % (WP, code)
    path = SCRATCH + "/run.php"
    open(path, "w").write(script)
    return subprocess.run(["php", path], capture_output=True, text=True).stdout


def restart_server():
    pid = open(ROOT + "/server.pid").read().strip() if os.path.exists(ROOT + "/server.pid") else ""
    subprocess.run(["pkill", "-f", "php -S localhost:8890"])
    time.sleep(0.8)
    p = subprocess.Popen(["php", "-S", "localhost:8890", "-t", "wp", "router.php"], cwd=ROOT,
                         env=dict(os.environ, PHP_CLI_SERVER_WORKERS="4"), stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                         start_new_session=True)
    open(ROOT + "/server.pid", "w").write(str(p.pid))
    time.sleep(1.5)


def install(src):
    shutil.rmtree(PLUG, ignore_errors=True)
    shutil.copytree(src, PLUG, ignore=shutil.ignore_patterns(".git", "tests", "dist", ".github"))
    restart_server()


def pkce():
    v = secrets.token_urlsafe(48)
    return v, base64.urlsafe_b64encode(hashlib.sha256(v.encode()).digest()).rstrip(b"=").decode()


def connect():
    s = requests.Session()
    s.get(BASE + "/wp-login.php")
    s.post(BASE + "/wp-login.php", data={"log": "admin", "pwd": "admin-test-pass", "testcookie": "1"}, allow_redirects=False)
    reg = requests.post(BASE + "/vr-oauth/register", json={"client_name": "Claude", "redirect_uris": [REDIRECT]}).json()
    v, c = pkce()
    p = {"response_type": "code", "client_id": reg["client_id"], "redirect_uri": REDIRECT, "code_challenge": c,
         "code_challenge_method": "S256", "state": "s"}
    page = s.get(BASE + "/vr-oauth/authorize", params=p)
    nonce = re.search(r'name="_vrnonce" value="([^"]+)"', page.text).group(1)
    d = s.post(BASE + "/vr-oauth/authorize", data=dict(p, _vrnonce=nonce, decision="approve"), allow_redirects=False)
    code = urllib.parse.parse_qs(urllib.parse.urlparse(d.headers["Location"]).query)["code"][0]
    tok = requests.post(BASE + "/vr-oauth/token", data={"grant_type": "authorization_code", "code": code,
                                                          "client_id": reg["client_id"], "code_verifier": v}).json()
    return tok, reg["client_id"], s


def tool(token, name, args=None):
    r = requests.post(MCP, json={"jsonrpc": "2.0", "id": 1, "method": "tools/call", "params": {"name": name, "arguments": args or {}}},
                      headers={"Authorization": "Bearer " + token})
    if r.status_code != 200:
        return True, f"HTTP {r.status_code}"
    res = r.json()["result"]
    t = res["content"][0]["text"]
    try:
        return res["isError"], json.loads(t)
    except ValueError:
        return res["isError"], t


def calls():
    out = php("echo wp_json_encode(get_option('vr_test_calls', array()));")
    try:
        return json.loads(out)
    except ValueError:
        return out


def reset_calls():
    php("delete_option('vr_test_calls');")


php("delete_transient('vr_rl_register_'.md5('127.0.0.1'));global $wpdb;$wpdb->query(\"DELETE FROM {$wpdb->options} WHERE option_name LIKE '%vr_rl_%'\");")

# ---------------------------------------------------------------- upgrade in place
install(OLD)
php("require_once ABSPATH.'wp-admin/includes/plugin.php'; activate_plugin('visual-realm/visual-realm.php');")
print(php("require_once ABSPATH.'wp-admin/includes/plugin.php'; echo get_plugin_data(WP_PLUGIN_DIR.'/visual-realm/visual-realm.php')['Version'];"))
tok, client_id, admin = connect()
AT = tok["access_token"]
err, ping = tool(AT, "mcp_ping")
check("connected on 0.1.3", not err and ping["plugin_version"] == "0.1.3", ping)

install(SRC)
err, ping = tool(AT, "mcp_ping")
check("same token works after upgrade to " + CUR, not err and ping["plugin_version"] == CUR, ping)
names = {t["name"] for t in requests.post(MCP, json={"jsonrpc": "2.0", "id": 1, "method": "tools/list"},
                                          headers={"Authorization": "Bearer " + AT}).json()["result"]["tools"]}
check("new tools listed", {"wp_update_menu_item", "wp_delete_menu_item", "wp_get_file"} <= names, names)
r1 = requests.post(BASE + "/vr-oauth/token", data={"grant_type": "refresh_token", "refresh_token": tok["refresh_token"], "client_id": client_id})
check("refresh token from 0.1.3 still works", r1.status_code == 200, r1.text)
AT = r1.json()["access_token"]

# ---------------------------------------------------------------- option key path
php("update_option('fusion_test_palette', array('color_palette'=>array('color4'=>array('color'=>'#ff6d20')),'big'=>str_repeat('x',30000)));"
    "update_option('avada_test_json', '{\\\\\"conditions\\\\\":[],\\\\\"template_terms\\\\\":{\\\\\"header\\\\\":\\\\\"1071\\\\\"}}');")
err, o = tool(AT, "wp_get_option", {"name": "fusion_test_palette"})
check("large option returns keys only", not err and "too_large" in o and "color_palette" in o["keys"], o)
err, o = tool(AT, "wp_get_option", {"name": "fusion_test_palette", "key": "color_palette.color4.color"})
check("option key path", not err and o["value"] == "#ff6d20", o)
err, o = tool(AT, "wp_get_option", {"name": "avada_test_json", "key": "template_terms.header"})
check("escaped JSON option decoded", not err and o.get("value") == "1071", o)
err, o = tool(AT, "wp_get_option", {"name": "fusion_test_palette", "key": "nope.x"})
check("missing key is a clear error", err and "Key not found" in o, o)

# ---------------------------------------------------------------- menus
menu_id = int(php("$m=wp_get_nav_menu_object('VR Test Menu'); if(!$m){$id=wp_create_nav_menu('VR Test Menu');}else{$id=$m->term_id;} echo $id;"))
page_id = int(php("echo wp_insert_post(array('post_type'=>'page','post_title'=>'Menu Target','post_status'=>'publish'));"))
err, add = tool(AT, "wp_add_menu_item", {"menu_id": menu_id, "object_id": page_id})
item = add["item_id"]
err, up = tool(AT, "wp_update_menu_item", {"item_id": item, "title": "Home & Living", "position": 3})
err, menus = tool(AT, "wp_get_menus")
it = [i for m in menus if m["id"] == menu_id for i in m["items"] if i["id"] == item][0]
check("menu item renamed and moved", it["title"] == "Home & Living" and it["object_id"] == page_id, it)
err, bad = tool(AT, "wp_update_menu_item", {"item_id": item, "url": "https://example.com"})
check("URL change refused on page links", err and "custom links" in bad, bad)
tool(AT, "mcp_undo_change", {"id": up["change_id"]})
err, menus = tool(AT, "wp_get_menus")
it = [i for m in menus if m["id"] == menu_id for i in m["items"] if i["id"] == item][0]
check("undo restores menu label", it["title"] == "Menu Target", it)
err, de = tool(AT, "wp_delete_menu_item", {"item_id": item})
err, menus = tool(AT, "wp_get_menus")
check("menu item removed", not any(i["id"] == item for m in menus for i in m["items"]), menus)
err, u = tool(AT, "mcp_undo_change", {"id": de["change_id"]})
err, menus = tool(AT, "wp_get_menus")
back = [i for m in menus if m["id"] == menu_id for i in m["items"] if i["object_id"] == page_id]
check("undo puts deleted menu item back", not err and len(back) == 1, (u, menus))

# ---------------------------------------------------------------- schema booleans
err, mm = tool(AT, "wp_update_post_meta", {"id": page_id, "key": "rank_math_schema_MedicalStudy",
                                           "value": {"metadata": {"type": "custom", "isPrimary": True}, "@type": "MedicalStudy",
                                                     "isAccessibleForFree": True, "nested": [{"flag": False}]}})
err, g = tool(AT, "wp_get_post_meta", {"id": page_id, "key": "rank_math_schema_MedicalStudy"})
v = g["value"]
check("schema booleans saved as strings", v["isAccessibleForFree"] == "true" and v["nested"][0]["flag"] == "false", v)
check("Rank Math metadata block untouched", v["metadata"]["isPrimary"] is True, v)
check("tool reports the conversion", "note" in mm, mm)

# ---------------------------------------------------------------- SVG upload + file read
evil = ('<?xml version="1.0"?><!-- Generator: Test --><svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" '
        'viewBox="0 0 10 10" onload="alert(1)"><script>alert(2)</script><foreignObject><div>x</div></foreignObject>'
        '<a xlink:href="javascript:alert(3)"><rect width="10" height="10" fill="#000"/></a>'
        '<path d="M0 0h10v10z" fill="#ff6d20" style="fill:url(https://evil.example/x)"/><use xlink:href="#p"/></svg>')
err, up = tool(AT, "wp_upload_media", {"data": base64.b64encode(evil.encode()).decode(), "filename": "hook-report-mark.svg",
                                       "title": "Hook Report mark", "alt": "Hook Report"})
check("SVG upload accepted", not err and up["mime"] == "image/svg+xml", up)
err, f = tool(AT, "wp_get_file", {"id": up["id"]})
body = f.get("content", "") if isinstance(f, dict) else ""
check("SVG content readable via wp_get_file", "<svg" in body, f)
check("scripts/handlers/foreignObject/js links stripped",
      all(x not in body.lower() for x in ("<script", "onload", "foreignobject", "javascript:", "evil.example", "generator")), body)
check("safe SVG parts kept", 'fill="#ff6d20"' in body and "<rect" in body and 'xlink:href="#p"' in body, body)
bad = '<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>'
err, r = tool(AT, "wp_upload_media", {"data": base64.b64encode(bad.encode()).decode(), "filename": "xxe.svg"})
check("SVG with entities refused", err and "entity" in str(r).lower(), r)
err, r = tool(AT, "wp_upload_media", {"data": base64.b64encode(b"<html>nope</html>").decode(), "filename": "x.svg"})
check("non-SVG refused", err, r)
err, r = tool(AT, "wp_get_file", {"url": BASE + "/wp-config.php"})
check("wp_get_file cannot leave uploads", err, r)
err, r = tool(AT, "wp_get_file", {"url": BASE + "/wp-content/uploads/../../wp-config.php"})
check("wp_get_file blocks ../ traversal", err, r)
png = base64.b64encode(bytes.fromhex("89504e470d0a1a0a0000000d4948445200000001000000010806000000"
                                     "1f15c4890000000d4944415478da63fcffff3f0300050001ff5d25d4f60000000049454e44ae426082")).decode()
err, img = tool(AT, "wp_upload_media", {"data": png, "filename": "pixel-test.png"})
err, f = tool(AT, "wp_get_file", {"id": img["id"]})
check("binary file needs base64 flag", "hint" in f, f)
err, f = tool(AT, "wp_get_file", {"id": img["id"], "base64": True})
check("binary file as base64", f.get("base64") == png, f)
err, f = tool(AT, "wp_upload_media", {"data": png, "filename": "after-svg.png"})
check("SVG allowance does not leak to later uploads", not err, f)
leak = php("echo isset(get_allowed_mime_types()['svg']) ? 'leak' : 'clean';")
check("svg not left in allowed types", leak.strip() == "clean", leak)

# ---------------------------------------------------------------- rendered find + counts list
err, r = tool(AT, "wp_get_rendered", {"id": page_id, "count": ["Menu Target", "Menu Target"], "find": "Menu Target"})
check("counts returned as list (no key collisions)", isinstance(r["counts"], list) and len(r["counts"]) == 2, r)
check("find returns excerpt", r["find"] and "Menu Target" in r["find"]["excerpt"], r)

# ---------------------------------------------------------------- cache purge paths
php("global $wpdb;$t=$wpdb->prefix.'wpr_rucss_used_css';$wpdb->query(\"DELETE FROM $t\");"
    "$u=untrailingslashit(get_permalink(%d));$wpdb->insert($t,array('url'=>$u.'/','css'=>'a'));$wpdb->insert($t,array('url'=>home_url('/other/'),'css'=>'b'));" % page_id)
reset_calls()
err, pc = tool(AT, "vr_purge_cache", {"id": page_id})
c = calls()
check("page purge: WP Rocket page", pc["purged"]["wp_rocket"] == "page" and f"rocket_clean_post:{page_id}" in c, (pc, c))
check("page purge: used CSS row for that page only", pc["purged"]["wp_rocket_used_css"] == "page (1 rows)", pc)
left = php("global $wpdb;echo $wpdb->get_var(\"SELECT COUNT(*) FROM {$wpdb->prefix}wpr_rucss_used_css\");")
check("other pages' used CSS kept", left.strip() == "1", left)
check("page purge: Avada per-page", pc["purged"]["avada"] == "page", pc)
section = int(php("echo wp_insert_post(array('post_type'=>'fusion_tb_section','post_title'=>'Default Header','post_status'=>'publish'));"))
reset_calls()
err, pc = tool(AT, "vr_purge_cache", {"id": section})
c = calls()
check("layout section escalates to site purge", pc["scope"] == "site" and "note" in pc, pc)
check("site purge: WP Rocket + minify + Avada reset", {"rocket_clean_domain", "rocket_clean_minify", "fusion_reset_all_caches"} <= set(c), c)
check("site purge: all used CSS cleared", pc["purged"]["wp_rocket_used_css"].startswith("site"), pc)
check("not on SiteGround reported", pc["purged"]["siteground"] == "not on SiteGround", pc)
err, pc = tool(AT, "vr_purge_cache", {"id": 999999})
check("purge unknown id is an error", err, pc)

# ---------------------------------------------------------------- GitHub self-update
rel = WP + "/release"
os.makedirs(rel, exist_ok=True)
build = SCRATCH + "/rel/visual-realm"
shutil.rmtree(os.path.dirname(build), ignore_errors=True)
shutil.copytree(SRC, build, ignore=shutil.ignore_patterns(".git", "tests", "dist"))
p = build + "/visual-realm.php"
src_txt = open(p).read()
open(p, "w").write(src_txt.replace(CUR, NEXT))
subprocess.run(["bash", "-c", f"VR_SIGNING_KEY_FILE={KEY} {build}/bin/build-release.sh {rel} >/dev/null"], check=True)
def release_json(sig=True):
    assets = [{"name": "visual-realm.zip", "url": "https://api.github.com/repos/VisualRealm/visual-realm-plugin/releases/assets/1",
               "browser_download_url": BASE + "/release/visual-realm.zip"}]
    if sig:
        assets.append({"name": "visual-realm.zip.sig", "url": "https://api.github.com/repos/VisualRealm/visual-realm-plugin/releases/assets/2",
                       "browser_download_url": BASE + "/release/visual-realm.zip.sig"})
    json.dump({"tag_name": "v" + NEXT, "draft": False, "body": "Test release", "published_at": "2026-11-11T18:00:00Z",
               "html_url": "https://github.com/VisualRealm/visual-realm-plugin/releases/tag/v" + NEXT, "assets": assets}, open(rel + "/release.json", "w"))

UPGRADE = ("require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';require_once ABSPATH.'wp-admin/includes/plugin.php';"
           "require_once ABSPATH.'wp-admin/includes/file.php';require_once ABSPATH.'wp-admin/includes/misc.php';"
           "delete_transient('vr_update_release');delete_site_transient('update_plugins');wp_update_plugins();"
           "wp_set_current_user(1);$sk=new Automatic_Upgrader_Skin();$u=new Plugin_Upgrader($sk);$rr=$u->bulk_upgrade(array('visual-realm/visual-realm.php'));"
           "$x=$rr['visual-realm/visual-realm.php'];echo 'RESULT:'.(is_wp_error($x)?'ERR '.$x->get_error_message():var_export((bool)$x,true)).' MSG:'.implode(' / ',$sk->get_upgrade_messages());")

# unsigned release is not offered
release_json(sig=False)
out = php("delete_transient('vr_update_release');delete_site_transient('update_plugins');wp_update_plugins();$t=get_site_transient('update_plugins');echo isset($t->response['visual-realm/visual-realm.php'])?'OFFERED':'NOT';")
check("release without signature is not offered", out.strip().endswith("NOT"), out[-200:])

# tampered zip is refused
release_json(sig=True)
good = open(rel + "/visual-realm.zip", "rb").read()
open(rel + "/visual-realm.zip", "wb").write(good[:-10] + b"tampered!!")
out = php(UPGRADE)
check("tampered release refused by signature check", "signature did not verify" in out, out[-400:])
open(rel + "/visual-realm.zip", "wb").write(good)
rj = json.load(open(rel + "/release.json")); rj["tag_name"] = "v" + NEXT2; json.dump(rj, open(rel + "/release.json", "w"))
out = php(UPGRADE)
check("signed zip whose version differs from the tag is refused", "does not contain a newer version" in out, out[-400:])
release_json(sig=True)
restart_server()
reset_calls()
out = php("delete_transient('vr_update_release');delete_site_transient('update_plugins');wp_update_plugins();"
          "$t=get_site_transient('update_plugins');$r=isset($t->response['visual-realm/visual-realm.php'])?$t->response['visual-realm/visual-realm.php']:null;"
          "echo wp_json_encode($r);")
try:
    upd = json.loads(out.strip().splitlines()[-1])
except Exception:
    upd = out
check("signed update offered from GitHub release", isinstance(upd, dict) and upd.get("new_version") == NEXT, upd)
out = php(UPGRADE)
check("signed one-click update installs", "RESULT:true" in out, out[-600:])
active = php("require_once ABSPATH.'wp-admin/includes/plugin.php';echo is_plugin_active('visual-realm/visual-realm.php')?'y':'n';")
check("plugin stays active after update", active.strip().endswith("y"), active)
restart_server()
err, ping = tool(AT, "mcp_ping")
check("connection survives self-update", not err and ping["plugin_version"] == NEXT, ping)
check("plugin folder name preserved", os.path.isfile(PLUG + "/visual-realm.php"), os.listdir(WP + "/wp-content/plugins"))

# ---------------------------------------------------------------- private repo token path
php("VR_Settings::save(array('update_token'=>'ghp_testtoken'));delete_transient('vr_update_release');")
reset_calls()
out = php("$i=VR_Updater::latest(true);echo $i['package'];$a=apply_filters('http_request_args',array(),'https://api.github.com/repos/VisualRealm/visual-realm-plugin/releases/assets/1');"
          "echo '|'.$a['headers']['Authorization'].'|'.$a['headers']['Accept'];")
check("private repo: API sent with token", "github_api:auth" in calls(), calls())
check("private repo: asset download uses API URL with token", "releases/assets/1|Bearer ghp_testtoken|application/octet-stream" in out, out)
err, o = tool(AT, "wp_get_option", {"name": "vr_settings"})
check("update token not readable through tools", err, o)
php("VR_Settings::save(array('update_token'=>''));")
shutil.rmtree(rel)

# ---------------------------------------------------------------- disconnect mid-session
fam = php("global $wpdb;echo $wpdb->get_var($wpdb->prepare(\"SELECT family FROM {$wpdb->prefix}vr_tokens WHERE token_hash=%s\",hash('sha256','" + AT + "')));").strip()
page = admin.get(BASE + "/wp-admin/admin.php?page=visual-realm")
nonce = re.search(r'name="_wpnonce" value="([^"]+)"[^>]*>\s*(?:<input type="hidden" name="_wp_http_referer"[^>]*>\s*)?<input type="hidden" name="action" value="vr_revoke"', page.text)
nonce = nonce.group(1) if nonce else re.findall(r'_wpnonce" value="([^"]+)"', page.text)[1]
d = admin.post(BASE + "/wp-admin/admin-post.php", data={"_wpnonce": nonce, "action": "vr_revoke", "family": fam}, allow_redirects=False)
check("Disconnect button works", d.status_code == 302 and "vr_msg=revoked" in d.headers.get("Location", ""), d.headers)
r = requests.post(MCP, json={"jsonrpc": "2.0", "id": 1, "method": "ping"}, headers={"Authorization": "Bearer " + AT})
check("disconnected token gets 401 + sign-in challenge", r.status_code == 401 and "invalid_token" in r.headers.get("WWW-Authenticate", ""), r.headers)


# ---------------------------------------------------------------- security regressions (from the review)
tok2, cid2, admin2 = connect()
AT2 = tok2["access_token"]
for bad_uri in ["https://evil.example\\@claude.ai/cb", "https://claude.ai/api/mcp/auth_callback\\@evil.example",
                "https://claude.ai/other/path", "https://evil.example/cb#https://claude.ai/api/mcp/auth_callback",
                "https://user@claude.ai/api/mcp/auth_callback", "https://claude.ai.evil.example/api/mcp/auth_callback",
                "https://claude.ai/api/mcp/auth_callback ", "http://localhost.evil.example/callback"]:
    r = requests.post(BASE + "/vr-oauth/register", json={"client_name": "Claude", "redirect_uris": [bad_uri]})
    check("DCR rejects redirect " + repr(bad_uri), r.status_code == 400, r.text)
r = requests.post(BASE + "/vr-oauth/register", json={"client_name": "Claude Code", "redirect_uris": ["http://localhost/callback", "http://127.0.0.1/callback"]})
check("DCR accepts Claude Code loopback callbacks", r.status_code == 201, r.text)
cc = r.json()["client_id"]
v, c = pkce()
p_ = {"response_type": "code", "client_id": cc, "redirect_uri": "http://localhost:51234/callback", "code_challenge": c, "code_challenge_method": "S256"}
page = admin2.get(BASE + "/vr-oauth/authorize", params=p_)
check("loopback with any port reaches consent", page.status_code == 200 and "Claude Code" in page.text, page.status_code)
check("consent warns for loopback", "your own computer" in page.text, "")
p_["redirect_uri"] = "http://localhost:51234/other"
page = admin2.get(BASE + "/vr-oauth/authorize", params=p_, allow_redirects=False)
check("loopback with other path refused", page.status_code == 400, page.status_code)

for nm in ["VR_SETTINGS", "vr_settings ", "Vr_Settings", "mailserver_pass", "vr_db_version"]:
    err, o = tool(AT2, "wp_get_option", {"name": nm})
    check("option read blocked: " + repr(nm), err, o)
for nm, val in [("Users_Can_Register", 1), ("users_can_register", 1), ("Default_Role", "administrator"), ("default_role", "administrator"),
                ("upload_path", "."), ("Siteurl", "https://evil.example"), ("active_plugins", []), ("wp_user_roles", {}),
                ("vr_settings", {}), ("cron", {}), ("woocommerce_enable_myaccount_registration", "yes"), ("template", "x")]:
    err, o = tool(AT2, "wp_update_option", {"name": nm, "value": val})
    check("option write blocked: " + nm, err, o)
ucr = php("echo get_option('users_can_register').'|'.get_option('default_role').'|'.get_option('upload_path');")
check("core options unchanged", ucr.strip() == "0|subscriber|", ucr)
err, o = tool(AT2, "wp_update_option", {"name": "fusion_test_palette", "value": {"color_palette": {"color4": {"color": "#000000"}}}})
check("Avada option still writable", not err, o)
php("update_option('fusion_probe_settings', array('google_maps_api_key'=>'AIzaSECRET','color'=>'#fff','nested'=>array('smtp_password'=>'hunter2')));")
err, o = tool(AT2, "wp_get_option", {"name": "fusion_probe_settings"})
check("secret keys redacted in option values", not err and o["value"]["google_maps_api_key"] == "[hidden]" and o["value"]["nested"]["smtp_password"] == "[hidden]" and o["value"]["color"] == "#fff", o)

php("update_option('upload_path', '.');")
err, f = tool(AT2, "wp_get_file", {"url": BASE + "/./wp-config.php", "base64": True})
php("delete_option('upload_path');")
check("wp_get_file cannot read wp-config even if upload_path is changed", err, f)
open(WP + "/wp-content/uploads/probe.php", "w").write("<?php echo 1;")
err, f = tool(AT2, "wp_get_file", {"url": BASE + "/wp-content/uploads/probe.php", "base64": True})
os.remove(WP + "/wp-content/uploads/probe.php")
check("wp_get_file refuses .php inside uploads", err, f)

svg = '<svg xmlns="http://www.w3.org/2000/svg"><a><script>alert(document.domain)</script><rect width="1" height="1"/></a><switch><a><foreignObject><b>x</b></foreignObject></a></switch></svg>'
err, up = tool(AT2, "wp_upload_media", {"data": base64.b64encode(svg.encode()).decode(), "filename": "nested-a.svg"})
err, f = tool(AT2, "wp_get_file", {"id": up["id"]})
body = f.get("content", "") if isinstance(f, dict) else str(f)
check("script nested in <a> stripped", "<script" not in body.lower() and "alert" not in body and "foreignobject" not in body.lower() and "<rect" in body, body)
err, r = tool(AT2, "wp_upload_media", {"data": base64.b64encode(b"<script>alert(1)</script>").decode(), "filename": "page.html"})
check(".html upload refused", err, r)
err, r = tool(AT2, "wp_upload_media", {"data": base64.b64encode(b"<?php echo 1;").decode(), "filename": "shell.php"})
check(".php upload refused", err, r)

err, r = tool(AT2, "wp_get_rendered", {"url": BASE + f"/?page_id={page_id}"})
check("rendered check reports redirects instead of following", not err and r["status"] in (301, 302) and "redirect_to" in r, r)
for u in ["http://localhost:22/", "http://evil.example/", "http://localhost:8890\\@evil.example/", "file:///etc/passwd", "http://user@localhost:8890/"]:
    err, r = tool(AT2, "wp_get_rendered", {"url": u})
    check("rendered check refuses " + u, err, r)

big = [{"jsonrpc": "2.0", "id": i, "method": "ping"} for i in range(25)]
r = requests.post(MCP, json=big, headers={"Authorization": "Bearer " + AT2})
check("oversized JSON-RPC batch refused", r.status_code == 400, r.status_code)


# follow-up review items
err, o = tool(AT2, "wp_get_option", {"name": "fusion_probe_settings"})
val = o["value"]; val["color"] = "#000"
err, w = tool(AT2, "wp_update_option", {"name": "fusion_probe_settings", "value": val})
real = php("echo wp_json_encode(get_option('fusion_probe_settings'));")
check("[hidden] placeholders keep the real secrets on write-back", not err and "AIzaSECRET" in real and "hunter2" in real and '"#000"' in real, (w, real))
err, w = tool(AT2, "wp_update_option", {"name": "fusion_probe_settings", "value": {"new_api_key": "[hidden]"}})
check("[hidden] with no stored secret is refused", err, w)
php("update_option('fusion_obj_probe', (object) array('stripe_secret_key'=>'sk_live_x','ok'=>1));")
err, o = tool(AT2, "wp_get_option", {"name": "fusion_obj_probe"})
check("secrets inside object values redacted", not err and "sk_live_x" not in json.dumps(o), o)
svg2 = '<svg xmlns="http://www.w3.org/2000/svg"><style>@\\69mport "https://x.example/a.css"; rect{fill:u\\72l(https://x.example/b)}</style><rect width="1" height="1" style="fill:u\\72l(https://x.example/c)"/></svg>'
err, up2 = tool(AT2, "wp_upload_media", {"data": base64.b64encode(svg2.encode()).decode(), "filename": "css-escape.svg"})
err, f2 = tool(AT2, "wp_get_file", {"id": up2["id"]})
b2 = f2.get("content", "") if isinstance(f2, dict) else str(f2)
check("CSS-escaped external loads stripped from SVG", "x.example" not in b2 and "<rect" in b2, b2)

# ---------------------------------------------------------------- restore the source build for later runs
install(SRC)

passed = sum(1 for _, ok in results if ok)
print(f"\n{passed}/{len(results)} passed")
sys.exit(0 if passed == len(results) else 1)
