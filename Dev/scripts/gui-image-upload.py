#!/usr/bin/env python3
"""
管理画面 EasyMDE の Markdown 画像アップロード/貼付け GUI 操作テスト

仕様: Dev/Docs/design/15-mcp-extension-markdown-image.md §9.6
対象: 起動済みの iroha Board（Docker）に対する実ブラウザ操作。
      PHPUnit（HTTP クライアント）では検出できない JS/DOM の問題を検証する。

必要環境:
  - Python 3.10+
  - Playwright（`pip install playwright && playwright install chromium`）
  - アプリ起動（既定 http://localhost:8082）

使い方:
  python3 Dev/scripts/gui-image-upload.py
  BASE_URL=http://localhost:8082 API_USER=admin API_PASS=adminpass \
    COURSE_ID=1 python3 Dev/scripts/gui-image-upload.py

注意:
  - コンテンツは保存しない（新規作成画面でアップロードのみ行う）。
  - プレビューはフォームの「プレビュー」ボタン（セッション経由のサーバサイド
    プレビュー）を開いて検証する。EasyMDE のリアルタイムプレビューとは別経路。
  - アップロードされた画像ファイルは webroot/uploads に残る。
  - 終了コード: 全チェック PASS なら 0、1 件でも FAIL なら 1。
"""
from __future__ import annotations

import base64
import json
import os
import sys
import tempfile

try:
    from playwright.sync_api import sync_playwright
except ImportError:
    print("SKIP: playwright が未インストールです。pip install playwright を実行してください。")
    sys.exit(0)

BASE_URL = os.environ.get("BASE_URL", "http://localhost:8082")
ADMIN_USER = os.environ.get("API_USER", "admin")
ADMIN_PASS = os.environ.get("API_PASS", "adminpass")
COURSE_ID = os.environ.get("COURSE_ID", "1")

# 1x1 PNG（実体判定を通すための最小の有効 PNG）
PNG = base64.b64decode(
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=="
)

results: list[tuple[str, bool, str]] = []


def check(name: str, ok: bool, detail: str = "") -> None:
    results.append((name, ok, detail))
    print(("PASS" if ok else "FAIL"), "-", name, ("(" + detail + ")" if detail else ""))


def main() -> int:
    tmpdir = tempfile.mkdtemp(prefix="irohaboard-gui-")
    png_path = os.path.join(tmpdir, "gui-test.png")
    txt_path = os.path.join(tmpdir, "gui-test.txt")
    with open(png_path, "wb") as f:
        f.write(PNG)
    with open(txt_path, "w") as f:
        f.write("not an image")

    with sync_playwright() as p:
        browser = p.chromium.launch()
        context = browser.new_context(
            viewport={"width": 1440, "height": 900},
            permissions=["clipboard-read", "clipboard-write"],
        )
        page = context.new_page()

        dialogs: list[str] = []
        page.on("dialog", lambda d: (dialogs.append(d.message), d.accept()))
        console_errors: list[str] = []
        page.on("console", lambda m: console_errors.append(m.text) if m.type == "error" else None)
        # 画像配信（/contents/file-image/）の HTTP ステータスを記録する。
        # プレビュー popup の読込は別ページのため context 単位で捕捉する。
        net: list[tuple[str, int]] = []
        context.on(
            "response",
            lambda r: net.append((r.url, r.status)) if "file-image" in r.url else None,
        )

        # 1. 管理者ログイン
        page.goto(f"{BASE_URL}/admin/users/login", wait_until="domcontentloaded")
        page.fill('input[name="username"]', ADMIN_USER)
        page.fill('input[name="password"]', ADMIN_PASS)
        page.click('input[type="submit"], button[type="submit"]')
        page.wait_for_load_state("domcontentloaded")
        check("1. 管理者ログイン", "users/login" not in page.url, page.url)

        # 2. コンテンツ新規作成画面（kind=markdown）
        page.goto(f"{BASE_URL}/admin/contents/add/{COURSE_ID}", wait_until="domcontentloaded")
        check("2. コンテンツ新規作成画面", page.locator('input[name="kind"]').count() > 0)
        page.check('input[name="kind"][value="markdown"]')
        page.wait_for_selector(".EasyMDEContainer", timeout=10000)

        # 3. EasyMDE 初期化とアップロード導線
        check("3. EasyMDE 初期化", page.locator(".CodeMirror").count() == 1)
        check("4. upload-image ボタン", page.locator(".editor-toolbar .fa-image").count() >= 1)
        check("5. 画像 file input 生成", page.locator(".imageInput").count() == 1)
        # EasyMDE の貼付け/ドロップは uploadImage オプションが true の場合のみ有効化される
        check(
            "6. 貼付け/D&D ハンドラ有効 (uploadImage)",
            page.evaluate("window.markdownEditor.options.uploadImage === true"),
        )

        # 4. ツールバーの upload-image ボタン → アップロード
        with page.expect_response(lambda r: "upload-image" in r.url, timeout=15000) as resp_info:
            with page.expect_file_chooser() as fc_info:
                page.click(".editor-toolbar .fa-image")
            fc_info.value.set_files(png_path)
        resp = resp_info.value
        body = resp.text()
        check("7. アップロード HTTP 200", resp.status == 200, f"{resp.status} {resp.url}")
        url = ""
        if body.strip().startswith("["):
            try:
                url = (json.loads(body) or [""])[0] or ""
            except ValueError:
                url = ""
        check("8. 応答が [url]", bool(url) and "file-image" in url, body[:120])
        check(
            "9. URL が /contents/file-image/（admin prefix なし）",
            url.startswith(f"{BASE_URL}/contents/file-image/") and "/admin/" not in url,
            url,
        )

        if url:
            page.wait_for_timeout(500)
            value = page.evaluate("window.markdownEditor ? markdownEditor.value() : ''")
            check("10. 本文に画像記法が挿入", url in value and "![" in value, value[:180])

            # ログインセッションで取得できること（未認証はログイン画面へリダイレクトされる）
            r = page.request.get(url)
            ct = r.headers.get("content-type", "")
            check("11. 画像 URL が取得可能", r.status == 200 and ct.startswith("image/"), f"{r.status} {ct}")

            page.click('.editor-toolbar .fa-eye')
            page.wait_for_timeout(500)
            check(
                "12. プレビューに img 表示",
                page.locator(f'.editor-preview img[src="{url}"]').count() == 1,
            )
            page.click('.editor-toolbar .fa-eye')  # プレビュー解除（ツールバー操作不能を回避）
            page.wait_for_timeout(300)

        # 5. クリップボード貼付け（実際の Ctrl+V）
        page.evaluate(
            """async (b64) => {
                const bin = atob(b64);
                const bytes = new Uint8Array(bin.length);
                for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
                await navigator.clipboard.write([new ClipboardItem({
                    'image/png': new Blob([bytes], { type: 'image/png' })
                })]);
            }""",
            base64.b64encode(PNG).decode(),
        )
        before = page.evaluate("markdownEditor.value()")
        page.click(".CodeMirror")
        page.keyboard.press("Control+V")
        page.wait_for_timeout(3000)
        after = page.evaluate("markdownEditor.value()")
        check("13. Ctrl+V 貼付けで画像が挿入", after.count("![") > before.count("!["), after[-120:])

        # 6. ドラッグ&ドロップ（HTML5 DataTransfer + drop イベント）
        #    EasyMDE の uploadImage は CodeMirror の drop ハンドラ経由で発火する。
        before_drop = page.evaluate("markdownEditor.value()")
        with page.expect_response(lambda r: "upload-image" in r.url, timeout=15000) as drop_resp_info:
            dispatched = page.evaluate(
                """async (b64) => {
                    const bin = atob(b64);
                    const bytes = new Uint8Array(bin.length);
                    for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
                    const dt = new DataTransfer();
                    dt.items.add(new File([bytes], 'drop-test.png', { type: 'image/png' }));
                    const el = document.querySelector('.EasyMDEContainer .CodeMirror-scroll');
                    if (!el) return false;
                    el.dispatchEvent(new DragEvent('drop', {
                        bubbles: true, cancelable: true, dataTransfer: dt,
                    }));
                    return true;
                }""",
                base64.b64encode(PNG).decode(),
            )
        drop_body = drop_resp_info.value.text()
        check(
            "14. D&D でアップロード HTTP 200",
            dispatched and drop_resp_info.value.status == 200,
            f"{drop_resp_info.value.status} {drop_body[:80]}",
        )
        page.wait_for_timeout(500)
        after_drop = page.evaluate("markdownEditor.value()")
        check(
            "15. D&D で本文に画像記法が挿入",
            after_drop.count("![") > before_drop.count("!["),
            after_drop[-120:],
        )

        # 7. コンテンツのプレビュー（#btnPreview → セッション経由のサーバサイドプレビュー）
        #    EasyMDE の編集中の値が textarea に同期されていないと本文が空のまま
        #    プレビューされ、画像も表示されない（#16 / #17 の回帰チェック）。
        net_mark = len(net)
        with context.expect_page(timeout=15000) as popup_info:
            with page.expect_response(lambda r: "/admin/contents/preview" in r.url, timeout=15000):
                page.click("#btnPreview")
        popup = popup_info.value
        popup.wait_for_load_state("domcontentloaded")
        popup.wait_for_timeout(1500)
        rendered = popup.evaluate("() => document.querySelector('.content-body').innerHTML")
        check(
            "16. プレビューに編集中の本文が反映される",
            rendered.strip() != "" and "<img" in rendered,
            rendered[:120],
        )
        loaded = popup.evaluate(
            "() => Array.from(document.querySelectorAll('.content-body img')).map(i => i.naturalWidth)"
        )
        check(
            "17. プレビューの画像が読み込める",
            len(loaded) > 0 and all(w > 0 for w in loaded),
            f"naturalWidth={loaded}",
        )
        preview_net = net[net_mark:]
        check(
            "18. プレビューの画像リクエストが 200",
            len(preview_net) > 0 and all(s == 200 for _, s in preview_net),
            str(preview_net[:5]),
        )
        popup.close()

        # 8. 非画像ファイルは拒否され、理由が通知される
        before_dialogs = len(dialogs)
        with page.expect_response(lambda r: "upload-image" in r.url, timeout=15000) as resp_info2:
            with page.expect_file_chooser() as fc_info2:
                page.click(".editor-toolbar .fa-image")
            fc_info2.value.set_files(txt_path)
        check("19. 非画像は [false]", resp_info2.value.text().strip() == "[false]")
        page.wait_for_timeout(700)
        check("20. エラー通知が表示", len(dialogs) > before_dialogs, dialogs[-1][:80] if dialogs else "")

        # 9. コンソールエラー（画像の 404 等）が無いこと
        check("21. JS コンソールエラーなし", len(console_errors) == 0, "; ".join(console_errors[:3]))

        context.close()
        browser.close()

    failed = [r for r in results if not r[1]]
    print(f"\n=== GUI image upload: {len(results) - len(failed)}/{len(results)} PASS ===")
    if failed:
        print("FAIL した項目:", ", ".join(r[0] for r in failed))
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
