"""Access controls: Claude-only server addresses, named accounts, content walls, tool switches, alerts, token lifetime."""
import base64, json, os, re, time
import requests
from vrtest import (BASE, MCP, REDIRECT, WP, check, finish, php, login, register, authorize, connect, rpc, tool,
                    settings, calls, reset_calls)

LOCAL = "127.0.0.1\n::1"

# ---------------------------------------------------------------- baseline connection
tok, cid, admin, _ = connect()
check("admin connects", tok is not None and tok.status_code == 200, tok and tok.text)
AT, RT = tok.json()["access_token"], tok.json()["refresh_token"]
err, ping = tool(AT, "mcp_ping")
check("token works from an allowed address", not err, ping)

# ---------------------------------------------------------------- server address allowlist
settings(extra_ips="")
r = requests.post(MCP, json={"jsonrpc": "2.0", "id": 1, "method": "ping"}, headers={"Authorization": "Bearer " + AT})
check("valid token refused from a non-Claude address", r.status_code == 403, r.status_code)
r = requests.post(MCP, json={"jsonrpc": "2.0", "id": 1, "method": "ping"})
check("unauthenticated MCP call refused before auth", r.status_code == 403 and "WWW-Authenticate" not in r.headers, r.status_code)
r = requests.post(BASE + "/vr-oauth/token", data={"grant_type": "refresh_token", "refresh_token": RT, "client_id": cid})
check("token endpoint refused", r.status_code == 403, r.status_code)
r = register()
check("registration refused", r.status_code == 403, r.status_code)
r = requests.post(BASE + "/vr-oauth/revoke", data={"token": RT, "client_id": cid})
check("revocation refused", r.status_code == 403, r.status_code)
r = requests.post(MCP, json={"jsonrpc": "2.0", "id": 1, "method": "ping"},
                  headers={"Authorization": "Bearer " + AT, "CF-Connecting-IP": "160.79.104.10", "X-Forwarded-For": "160.79.104.10"})
check("spoofed CF-Connecting-IP / X-Forwarded-For from a non-Cloudflare peer refused", r.status_code == 403, r.status_code)
r = requests.get(BASE + "/vr-oauth/authorize", params={"client_id": cid, "response_type": "code"}, allow_redirects=False)
check("browser sign-in page not blocked by the allowlist", r.status_code in (302, 400), r.status_code)
r = requests.get(BASE + "/?rest_route=/visual-realm/v1/.well-known/oauth-protected-resource")
check("discovery metadata still public", r.status_code == 200, r.status_code)
log = json.loads(php("echo wp_json_encode(VR_Access::blocked_log());") or "[]")
check("refused requests logged with address and endpoint",
      len(log) == 1 and log[0]["where"] == "mcp" and log[0]["ip"] in ("127.0.0.1", "::1"), log)
n_before = len(log)
requests.post(MCP, json={"jsonrpc": "2.0", "id": 1, "method": "ping"})
check("repeat refusals are not re-logged (no DB churn under attack)", len(json.loads(php("echo wp_json_encode(VR_Access::blocked_log());"))) == n_before, "")

def allowed(remote, extra=None):
    srv = {"REMOTE_ADDR": remote}
    srv.update(extra or {})
    return php("echo VR_Access::server_allowed('test') ? 'yes' : 'no';", srv)

check("Anthropic range 160.79.104.0/21 allowed", allowed("160.79.105.3") == "yes")
check("Anthropic range 153.61.208.0/20 upper edge allowed", allowed("153.61.223.255") == "yes")
check("just outside 160.79.104.0/21 refused", allowed("160.79.112.0") == "no")
check("just outside 153.61.208.0/20 refused", allowed("153.61.224.0") == "no")
check("IPv4-mapped IPv6 handled", allowed("::ffff:160.79.104.9") == "yes")
check("Claude behind Cloudflare (v4 edge) allowed via CF-Connecting-IP", allowed("162.158.1.1", {"HTTP_CF_CONNECTING_IP": "160.79.104.20"}) == "yes")
check("Claude behind Cloudflare (v6 edge) allowed", allowed("2606:4700::1", {"HTTP_CF_CONNECTING_IP": "160.79.104.20"}) == "yes")
check("attacker behind Cloudflare refused", allowed("162.158.1.1", {"HTTP_CF_CONNECTING_IP": "203.0.113.5"}) == "no")
check("CF header from a non-Cloudflare peer ignored", allowed("203.0.113.5", {"HTTP_CF_CONNECTING_IP": "160.79.104.20"}) == "no")
check("X-Forwarded-For ignored", allowed("203.0.113.5", {"HTTP_X_FORWARDED_FOR": "160.79.104.20"}) == "no")
check("garbage REMOTE_ADDR refused", allowed("not-an-ip") == "no")
ranges = php("echo implode('|', VR_Access::parse_ranges(\"10.0.0.0/8 1.2.3.4 2001:db8::/16 junk 5.6.7.0/24 2001:db8::/48 1.2.3.4/33\"));")
check("extra ranges validated (too-wide and malformed dropped)", ranges == "1.2.3.4|5.6.7.0/24|2001:db8::/48", ranges)
settings(extra_ips="203.0.113.0/24")
check("extra range allowed", allowed("203.0.113.77") == "yes")
settings(restrict_ips=0, extra_ips="")
check("restriction can be switched off", allowed("198.51.100.1") == "yes")
settings(restrict_ips=1, extra_ips=LOCAL)
err, ping = tool(AT, "mcp_ping")
check("allowed again after restoring the local range", not err, ping)

# ---------------------------------------------------------------- named accounts
uid2 = int(php("$u=get_user_by('login','admin2');if(!$u){$id=wp_create_user('admin2','admin2-test-pass','admin2@example.com');(new WP_User($id))->set_role('administrator');}else{$id=$u->ID;}echo $id;"))
php("VR_Access::set_allowed_users(array(1));")
reset_calls()
tok2, cid2, s2, page = connect("admin2", "admin2-test-pass")
check("administrator not on the list cannot connect", tok2 is None and page.status_code == 400 and "allowed to connect" in page.text, page.status_code)
php("wp_set_current_user(1);VR_Access::set_allowed_users(array(1,%d));" % uid2)
mails = [c for c in calls() if c.startswith("mail:")]
check("alert emailed when the account list changes", any("Accounts allowed to connect changed" in m and "Added: admin2" in m for m in mails), mails)
reset_calls()
tok2, cid2, s2, page = connect("admin2", "admin2-test-pass")
check("named administrator can connect", tok2 is not None and tok2.status_code == 200, page.status_code)
mails = [c for c in calls() if c.startswith("mail:")]
check("connection alert emailed to every named account",
      len(mails) == 1 and "admin2@example.com" in mails[0] and "kyle@example.com" in mails[0], mails)
check("alert names account, app and address",
      mails and "Account: admin2" in mails[0] and "App: Claude" in mails[0] and ("127.0.0.1" in mails[0] or "address: ::1" in mails[0]) and "page=visual-realm" in mails[0], mails)
AT2, RT2 = tok2.json()["access_token"], tok2.json()["refresh_token"]
check("second account's token works", not tool(AT2, "mcp_ping")[0])

# an approved code must not survive removal
s3 = login("admin2", "admin2-test-pass")
cid3 = register().json()["client_id"]
_, d3, v3 = authorize(s3, cid3)
code3 = re.search(r"code=([^&]+)", d3.headers["Location"]).group(1)
php("wp_set_current_user(1);VR_Access::set_allowed_users(array(1));")
r = requests.post(MCP, json={"jsonrpc": "2.0", "id": 1, "method": "ping"}, headers={"Authorization": "Bearer " + AT2})
check("removed account's token stops working immediately", r.status_code == 401, r.status_code)
r = requests.post(BASE + "/vr-oauth/token", data={"grant_type": "refresh_token", "refresh_token": RT2, "client_id": cid2})
check("removed account cannot refresh", r.status_code == 400 and "invalid_grant" in r.text, r.text)
r = requests.post(BASE + "/vr-oauth/token", data={"grant_type": "authorization_code", "code": code3, "client_id": cid3, "code_verifier": v3})
check("code approved before removal cannot be exchanged", r.status_code == 400, r.text)
revoked = php("global $wpdb;echo $wpdb->get_var($wpdb->prepare(\"SELECT COUNT(*) FROM {$wpdb->prefix}vr_tokens WHERE user_id=%%d AND revoked=0\", %d));" % uid2)
check("removed account's tokens revoked in the database", revoked == "0", revoked)
php("$u=new WP_User(1);")  # no-op, keeps admin
check("admin still connected", not tool(AT, "mcp_ping")[0])
php("(new WP_User(%d))->set_role('editor');wp_set_current_user(1);VR_Access::set_allowed_users(array(1,%d));" % (uid2, uid2))
check("named account without the required role is refused",
      php("echo VR_Access::user_allowed(%d) ? 'yes' : 'no';" % uid2) == "no")
php("(new WP_User(%d))->set_role('administrator');wp_set_current_user(1);VR_Access::set_allowed_users(array(1));" % uid2)

# ---------------------------------------------------------------- content walls
order = int(php("echo wp_insert_post(array('post_type'=>'shop_order','post_title'=>'Order 1001','post_status'=>'publish'));"))
secret = int(php("echo wp_insert_post(array('post_type'=>'secret_cpt','post_title'=>'Secret 1','post_status'=>'publish'));"))
php("update_post_meta(%d,'_billing_ssn','123-45-6789');" % order)
for nm, args in [("wp_get_post", {"id": order}), ("wp_get_post_meta", {"id": order}), ("wp_get_seo", {"id": order}),
                 ("wp_update_post", {"id": order, "title": "x"}), ("wp_get_revisions", {"id": order}),
                 ("wp_delete_post", {"id": order}), ("wp_get_post", {"id": secret}),
                 ("wp_get_posts", {"post_type": "shop_order"}), ("wp_get_posts", {"post_type": "secret_cpt"}),
                 ("wp_create_post", {"post_type": "shop_order", "title": "x"}), ("wp_get_rendered", {"id": order}),
                 ("wp_update_post_meta", {"id": order, "key": "x", "value": "y"})]:
    err, o = tool(AT, nm, args)
    check(f"walled: {nm} {json.dumps(args)}", err and "123-45-6789" not in str(o), o)
err, lst = tool(AT, "wp_get_posts", {"per_page": 100})
ids = {p["id"] for p in lst["posts"]} if not err else set()
check("listing 'any' leaves out walled types", not err and order not in ids and secret not in ids, lst if err else len(ids))
err, types = tool(AT, "wp_get_post_types")
names = {t["name"] for t in types} if not err else set()
check("post type list leaves out walled types", "shop_order" not in names and "secret_cpt" not in names and "page" in names, names)
menu_id = int(php("$m=wp_get_nav_menu_object('Wall Menu');echo $m?$m->term_id:wp_create_nav_menu('Wall Menu');"))
err, o = tool(AT, "wp_add_menu_item", {"menu_id": menu_id, "object_id": order})
check("menu item cannot point at a walled post", err, o)
settings(extra_post_types="secret_cpt, shop_order, gf_entry")
err, o = tool(AT, "wp_get_post", {"id": secret})
check("opted-in content type reachable", not err and o["title"] == "Secret 1", o)
err, o = tool(AT, "wp_get_post", {"id": order})
check("orders stay walled even when added to the list", err, o)
check("never-types filtered from the allowed list",
      php("echo implode(',', array_intersect(array('shop_order','gf_entry'), VR_Access::post_types()));") == "")
settings(extra_post_types="")

# ---------------------------------------------------------------- media-only file reads
png = base64.b64encode(bytes.fromhex("89504e470d0a1a0a0000000d4948445200000001000000010806000000"
                                     "1f15c4890000000d4944415478da63fcffff3f0300050001ff5d25d4f60000000049454e44ae426082")).decode()
err, img = tool(AT, "wp_upload_media", {"data": png, "filename": "wall-test.png"})
err, f = tool(AT, "wp_get_file", {"url": img["url"]})
check("media library file readable by URL", not err and f["size"] > 0, f)
sized = php("$f=get_attached_file(%d);$d=dirname($f);$n=pathinfo($f,PATHINFO_FILENAME);copy($f,\"$d/$n-150x150.png\");"
            "$m=wp_get_attachment_metadata(%d);if(!is_array($m)){$m=array();}$m['sizes']=array('thumbnail'=>array('file'=>\"$n-150x150.png\",'width'=>150,'height'=>150,'mime-type'=>'image/png'));"
            "wp_update_attachment_metadata(%d,$m);echo wp_get_attachment_image_url(%d,'thumbnail');" % (img["id"], img["id"], img["id"], img["id"]))
err, f = tool(AT, "wp_get_file", {"url": sized})
check("generated image size readable", not err, (sized, f))
up = WP + "/wp-content/uploads"
os.makedirs(up + "/2026/11", exist_ok=True)
open(up + "/2026/11/loose-notes.txt", "w").write("private notes")
err, f = tool(AT, "wp_get_file", {"url": BASE + "/wp-content/uploads/2026/11/loose-notes.txt"})
check("file in uploads that is not a media item refused", err and "private notes" not in str(f), f)
os.makedirs(up + "/gravity_forms/1-abc/2026/11", exist_ok=True)
open(up + "/gravity_forms/1-abc/2026/11/application.txt", "w").write("SSN 123-45-6789")
err, f = tool(AT, "wp_get_file", {"url": BASE + "/wp-content/uploads/gravity_forms/1-abc/2026/11/application.txt"})
check("form upload folder refused", err and "123-45-6789" not in str(f), f)
gf_att = int(php("$id=wp_insert_attachment(array('post_title'=>'app','post_mime_type'=>'text/plain','post_status'=>'inherit'), '%s/gravity_forms/1-abc/2026/11/application.txt');echo $id;" % up))
err, f = tool(AT, "wp_get_file", {"id": gf_att})
check("form upload refused even when registered as an attachment", err and "123-45-6789" not in str(f), f)

# ---------------------------------------------------------------- form plugin options
php("update_option('rg_gforms_key','abc');update_option('gform_settings',array('x'=>1));update_option('wpforms_settings',array('y'=>1));update_option('gravityformsaddon_zapier_settings',array('z'=>1));")
for nm in ["rg_gforms_key", "gform_settings", "wpforms_settings", "gravityformsaddon_zapier_settings", "wpcf7", "frm_options", "nf_form_1"]:
    err, o = tool(AT, "wp_get_option", {"name": nm})
    check("form option unreadable: " + nm, err, o)
err, o = tool(AT, "wp_get_option", {"name": "blogname"})
check("ordinary option still readable", not err, o)

# ---------------------------------------------------------------- tool switches
settings(disabled_tools=["wp_delete_post", "mcp_ping"])
names = {t["name"] for t in rpc(AT, "tools/list").json()["result"]["tools"]}
check("switched-off tool hidden from the list", "wp_delete_post" not in names and "wp_get_post" in names, names)
check("mcp_ping cannot be switched off", "mcp_ping" in names and not tool(AT, "mcp_ping")[0])
page_id = int(php("echo wp_insert_post(array('post_type'=>'page','post_title'=>'Keep Me','post_status'=>'draft'));"))
err, o = tool(AT, "wp_delete_post", {"id": page_id})
check("switched-off tool refuses calls", err and "switched off" in str(o), o)
check("page untouched", php("echo get_post_status(%d);" % page_id) == "draft")
settings(disabled_tools=[])

# settings screen round trip
all_tools = sorted({t["name"] for t in rpc(AT, "tools/list").json()["result"]["tools"]})
pg = admin.get(BASE + "/wp-admin/admin.php?page=visual-realm")
check("settings screen shows the security section", "Accounts allowed to connect" in pg.text and "Server addresses" in pg.text and "Tools" in pg.text, pg.status_code)
nonce = re.search(r'name="_wpnonce" value="([^"]+)"[^>]*>\s*<input type="hidden" name="_wp_http_referer"[^>]*>\s*<input type="hidden" name="action" value="vr_save_settings"', pg.text)
nonce = nonce.group(1) if nonce else ""
form = [("_wpnonce", nonce), ("action", "vr_save_settings"), ("capability", "manage_options"), ("redirect_uris", REDIRECT),
        ("allow_loopback", "1"), ("restrict_ips", "1"), ("extra_ips", "127.0.0.1\n::1\n10.0.0.0/8"), ("extra_post_types", "secret_cpt"),
        ("shown_users[]", "1"), ("allowed_users[]", "1"), ("allowed_users[]", "999999")]
form += [("tools[]", t) for t in all_tools if t != "wp_upload_media"]
reset_calls()
d = admin.post(BASE + "/wp-admin/admin-post.php", data=form, allow_redirects=False)
mails = [c for c in calls() if c.startswith("mail:")]
check("security settings change emails the named accounts", any("security settings changed" in m and "Switched-off tools" in m and "Extra content types" in m for m in mails), mails)
check("settings save", d.status_code == 302 and "vr_msg=saved" in d.headers.get("Location", ""), (d.status_code, d.headers.get("Location")))
s = json.loads(php("echo wp_json_encode(VR_Settings::get());"))
check("saved: tool switched off", s["disabled_tools"] == ["wp_upload_media"], s["disabled_tools"])
check("saved: too-wide range dropped", s["extra_ips"] == "127.0.0.1\n::1", s["extra_ips"])
check("saved: unknown account ids ignored", s["allowed_users"] == [1], s["allowed_users"])
check("saved: extra content type", s["extra_post_types"] == "secret_cpt", s["extra_post_types"])
form = [(k, v) for k, v in form if k != "restrict_ips"]
admin.post(BASE + "/wp-admin/admin-post.php", data=form, allow_redirects=False)
check("restriction checkbox can be cleared", php("echo (int) VR_Settings::get('restrict_ips');") == "0")
settings(restrict_ips=1, extra_ips=LOCAL, disabled_tools=[], extra_post_types="", allowed_users=[1], allow_loopback=1)


# ---------------------------------------------------------------- review regressions
# Files: attachments cannot be pointed at arbitrary files
err, o = tool(AT, "wp_create_post", {"post_type": "attachment", "title": "x", "meta": {"_wp_attached_file": "fusion-forms/7/loan-app.txt"}})
check("attachments cannot be created outside uploads", err, o)
err, o = tool(AT, "wp_create_post", {"post_type": "page", "title": "x", "meta": {"_wp_attached_file": "fusion-forms/7/loan-app.txt"}})
check("protected meta refused on create", err, o)
err, img2 = tool(AT, "wp_upload_media", {"data": png, "filename": "repoint-test.png"})
for k in ["_wp_attached_file", "_wp_attachment_metadata", "_menu_item_object_id", "_thumbnail_id"]:
    err, o = tool(AT, "wp_update_post_meta", {"id": img2["id"], "key": k, "value": "fusion-forms/7/loan-app.txt"})
    check("protected meta write refused: " + k, err, o)
    err, o = tool(AT, "wp_delete_post_meta", {"id": img2["id"], "key": k})
    check("protected meta delete refused: " + k, err, o)
for d_, fname in [("fusion-forms/7", "loan-app.txt"), ("wpallexport/exports", "orders.csv"), ("woocommerce_uploads", "file.pdf")]:
    os.makedirs(f"{up}/{d_}", exist_ok=True)
    open(f"{up}/{d_}/{fname}", "w").write("SSN 123-45-6789")
    aid = int(php("echo wp_insert_attachment(array('post_title'=>'p','post_mime_type'=>'text/plain','post_status'=>'inherit'), '%s/%s/%s');" % (up, d_, fname)))
    err, f = tool(AT, "wp_get_file", {"id": aid})
    check(f"plugin folder refused even with an attachment row: {d_}", err and "123-45-6789" not in str(f), f)
    err, f = tool(AT, "wp_get_rendered", {"url": f"{BASE}/wp-content/uploads/{d_}/{fname}"})
    check(f"rendered fetch refuses uploads: {d_}", err and "123-45-6789" not in str(f), f)
for u in [BASE + "/wp-content/debug.log", BASE + "/wp-json/wp/v2/users", BASE + "/?rest_route=/wp/v2/users", BASE + "/wp-admin/",
          BASE + "/wp-content/uploads/2026/11/loose-notes.txt", BASE + "/backup.sql", BASE + "/%77p-content/debug.log"]:
    err, f = tool(AT, "wp_get_rendered", {"url": u})
    check("rendered fetch refuses " + u.replace(BASE, ""), err, f)
err, f = tool(AT, "wp_get_rendered", {"url": BASE + "/robots.txt"})
check("rendered fetch allows robots.txt", not err, f)
err, f = tool(AT, "wp_get_rendered", {"url": BASE + "/"})
check("rendered fetch allows pages", not err, f)

# undo cannot delete files outside the media library, nor bypass switches
err, img3 = tool(AT, "wp_upload_media", {"data": png, "filename": "undo-guard.png"})
settings(disabled_tools=["wp_upload_media"])
err, o = tool(AT, "mcp_undo_change", {"id": img3["change_id"]})
check("undo refused while the original tool is switched off", err and "switched off" in str(o), o)
settings(disabled_tools=[])
open(f"{up}/gravity_forms/.htaccess", "w").write("deny from all")
php("update_post_meta(%d,'_wp_attached_file','gravity_forms/.htaccess');" % img3["id"])
err, o = tool(AT, "mcp_undo_change", {"id": img3["change_id"]})
check("undo will not delete a file outside the media library", err and os.path.exists(f"{up}/gravity_forms/.htaccess"), o)

# menus and parents do not reveal walled posts
item = int(php("$m=wp_get_nav_menu_object('Wall Menu');echo wp_update_nav_menu_item($m->term_id,0,array('menu-item-type'=>'post_type','menu-item-object'=>'shop_order','menu-item-object-id'=>%d,'menu-item-status'=>'publish'));" % order))
err, menus = tool(AT, "wp_get_menus")
check("menu items pointing at walled posts are hidden", not err and "Order 1001" not in json.dumps(menus) and "hidden" in json.dumps(menus), menus)
pg2 = int(php("echo wp_insert_post(array('post_type'=>'page','post_title'=>'Child','post_status'=>'draft'));"))
err, o = tool(AT, "wp_update_post", {"id": pg2, "parent": order})
check("walled post cannot become a parent", err, o)

# form data in transients and other option names
php("set_transient('wpforms_entry_cache_1','SSN 123-45-6789');set_transient('gform_entries_cache','SSN 123-45-6789');update_option('fusion_form_submissions_cache','SSN');update_option('fusion_options',array('primary_color'=>'#fff'));")
for nm in ["_transient_wpforms_entry_cache_1", "_transient_gform_entries_cache", "_site_transient_update_plugins", "cron", "fusion_form_submissions_cache"]:
    err, o = tool(AT, "wp_get_option", {"name": nm})
    check("option unreadable: " + nm, err and "123-45-6789" not in str(o), o)
err, o = tool(AT, "wp_get_option", {"name": "fusion_options"})
check("Avada theme options still readable", not err, o)

# blocked log cannot be used to fill the options table
settings(extra_ips="")
rows0 = php("global $wpdb;echo $wpdb->get_var(\"SELECT COUNT(*) FROM {$wpdb->options}\");")
for i in range(15):
    requests.post(f"{BASE}/vr-oauth/x{i}")
    requests.post(MCP, json={})
rows1 = php("global $wpdb;echo $wpdb->get_var(\"SELECT COUNT(*) FROM {$wpdb->options}\");")
check("unknown OAuth paths are 404s and refusals add no option rows", requests.post(BASE + "/vr-oauth/x99").status_code == 404 and int(rows1) - int(rows0) <= 2, (rows0, rows1))
settings(extra_ips=LOCAL)

# revocation is bound to the client that owns the token
other = register().json()["client_id"]
requests.post(BASE + "/vr-oauth/revoke", data={"token": AT, "client_id": other})
check("another client cannot revoke someone else's token", not tool(AT, "mcp_ping")[0])

# settings save keeps named accounts that were not on screen
php("wp_set_current_user(1);VR_Access::set_allowed_users(array(1,%d));" % uid2)
pg = admin.get(BASE + "/wp-admin/admin.php?page=visual-realm")
nonce = re.search(r'name="_wpnonce" value="([^"]+)"[^>]*>\s*<input type="hidden" name="_wp_http_referer"[^>]*>\s*<input type="hidden" name="action" value="vr_save_settings"', pg.text).group(1)
base_form = [("_wpnonce", nonce), ("action", "vr_save_settings"), ("capability", "manage_options"), ("redirect_uris", REDIRECT),
             ("restrict_ips", "1"), ("extra_ips", LOCAL), ("shown_users[]", "1"), ("allowed_users[]", "1")] + [("tools[]", t) for t in all_tools]
admin.post(BASE + "/wp-admin/admin-post.php", data=base_form, allow_redirects=False)
check("accounts not shown on the form stay named", sorted(json.loads(php("echo wp_json_encode(VR_Access::allowed_users());"))) == sorted([1, uid2]))
php("wp_set_current_user(1);VR_Access::set_allowed_users(array(1));")
settings(allow_loopback=1)

# ---------------------------------------------------------------- token lifetime and migration
exp = php("global $wpdb;echo strtotime($wpdb->get_var($wpdb->prepare(\"SELECT expires_at FROM {$wpdb->prefix}vr_tokens WHERE token_hash=%s\", hash('sha256','" + RT + "'))).' UTC')-time();")
check("refresh tokens last 30 days", 29 * 86400 < int(exp) <= 30 * 86400, exp)
reset_calls()
php("global $wpdb;$wpdb->query(\"UPDATE {$wpdb->prefix}vr_tokens SET revoked=1\");$wpdb->insert($wpdb->prefix.'vr_tokens',array('token_hash'=>hash('sha256','legacy'),'token_type'=>'refresh','family'=>str_repeat('a',32),'client_id'=>'legacy','user_id'=>%d,'scope'=>'mcp','resource'=>'','expires_at'=>gmdate('Y-m-d H:i:s',time()+90*86400),'created_at'=>gmdate('Y-m-d H:i:s')));"
    "$s=(array)get_option('vr_settings');$s['allowed_users']=array();$s['refresh_ttl']=90*86400;update_option('vr_settings',$s,false);update_option('vr_access_rev',2,false);" % uid2)
admin.get(BASE + "/")
mig = json.loads(php("global $wpdb;echo wp_json_encode(array('users'=>VR_Access::allowed_users(),'ttl'=>(int)VR_Settings::get('refresh_ttl'),'legacy'=>strtotime($wpdb->get_var(\"SELECT expires_at FROM {$wpdb->prefix}vr_tokens WHERE client_id='legacy'\").' UTC')-time(),'rev'=>(int)get_option('vr_access_rev')));"))
check("upgrade names only accounts that were already connected, not whoever browses", mig["users"] == [uid2], mig)
check("upgrade shortens refresh lifetime to 30 days", mig["ttl"] == 30 * 86400 and mig["legacy"] <= 30 * 86400 + 60, mig)
check("upgrade runs once", mig["rev"] == 3, mig)
mails = [c for c in calls() if c.startswith("mail:")]
check("upgrade emails the accounts it named", any("connection accounts named" in m and "admin2" in m for m in mails), mails)
php("global $wpdb;$wpdb->delete($wpdb->prefix.'vr_tokens',array('client_id'=>'legacy'));wp_set_current_user(1);VR_Access::set_allowed_users(array(1));")

finish()
