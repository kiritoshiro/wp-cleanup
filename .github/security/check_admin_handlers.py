"""Fail if an admin-post or AJAX handler skips its capability or nonce check.

Every `admin_post_*` / `wp_ajax_*` action registered in includes/class-admin.php
must call guard() (or current_user_can) and check_admin_referer/check_ajax_referer
in its own body. This has regressed twice: handlers once relied on a guard()
that no longer verified nonces.
"""
import re
import sys

PATH = "includes/class-admin.php"

src = open(PATH, encoding="utf-8").read()
hooks = re.findall(
    r"add_action\(\s*'((?:admin_post|wp_ajax)_[A-Za-z0-9_]+)',\s*array\(\s*\$this,\s*'([A-Za-z0-9_]+)'",
    src,
)
if not hooks:
    sys.exit(f"No admin-post/AJAX handlers found in {PATH}; update this check.")

failed = False
for hook, method in hooks:
    match = re.search(r"function " + re.escape(method) + r"\s*\([^)]*\)\s*\{(.*?)\n\t\}", src, re.S)
    body = match.group(1) if match else ""
    has_cap = "$this->guard(" in body or "current_user_can(" in body
    has_nonce = re.search(r"check_(?:admin|ajax)_referer\(", body) is not None
    if not (has_cap and has_nonce):
        failed = True
        print(f"::error file={PATH}::{hook} -> {method}() is missing "
              + " and ".join(x for x, ok in (("a capability check", has_cap), ("a nonce check", has_nonce)) if not ok))
print(f"Checked {len(hooks)} admin handlers.")
sys.exit(1 if failed else 0)
