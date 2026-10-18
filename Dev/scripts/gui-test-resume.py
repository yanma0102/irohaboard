#!/usr/bin/env python3
"""
試験のセッション切れ対策（回答の退避・復元）GUI 操作テスト

仕様: Dev/Docs/design/16-test-answer-resume.md
対象: 起動済みの iroha Board（Docker）に対する実ブラウザ操作。
      PHPUnit（HTTP クライアント）では検出できない JS/localStorage/Cookie の
      挙動を検証する。

検証すること:
  1. 回答が localStorage に出題内容とともに退避される
  2. 出題リストが Cookie にも退避される（サーバが初回リクエストで復元するため）
  3. セッション（Cookie）が失われても回答は失われない
  4. 再ログイン後にテスト画面を開くと、同じ出題内容で回答が復元される
  5. 復元は再読込なしの 1 リクエストで完了する（セッション切れと重なる
     2 回目のリクエストを発生させない）
  6. 復元した回答で採点でき、元と同じ出題内容が採点される
  7. 採点後に退避データ（localStorage・Cookie）が削除される
  8. JS コンソールエラーが無い

必要環境:
  - Python 3.10+ / Playwright（`pip install playwright && playwright install chromium`）
  - アプリ起動（既定 http://localhost:8082）

使い方:
  python3 Dev/scripts/gui-test-resume.py
  BASE_URL=http://localhost:8082 API_USER=admin API_PASS=adminpass \
    USER_ID=1 COURSE_ID=1 TEST_CONTENT_ID=7 python3 Dev/scripts/gui-test-resume.py

注意:
  - 受講登録は REST API で行う。テスト前に未登録だった場合のみ、終了時に解除する。
  - 採点により学習履歴（ib_records）が 1 件作成される（作成された
    record_id を出力する。REST API に削除エンドポイントは無い）。
  - 終了コード: 全チェック PASS なら 0、1 件でも FAIL なら 1。
"""
from __future__ import annotations

import json
import os
import sys

try:
    from playwright.sync_api import sync_playwright
except ImportError:
    print("SKIP: playwright が未インストールです。pip install playwright を実行してください。")
    sys.exit(0)

BASE_URL = os.environ.get("BASE_URL", "http://localhost:8082")
ADMIN_USER = os.environ.get("API_USER", "admin")
ADMIN_PASS = os.environ.get("API_PASS", "adminpass")
USER_ID = os.environ.get("USER_ID", "1")
COURSE_ID = os.environ.get("COURSE_ID", "1")
TEST_CONTENT_ID = os.environ.get("TEST_CONTENT_ID", "7")

STATE_COOKIE = f"IrohaTestState_{TEST_CONTENT_ID}"

results: list[tuple[str, bool, str]] = []


def check(name: str, ok: bool, detail: str = "") -> None:
    results.append((name, ok, detail))
    print(("PASS" if ok else "FAIL"), "-", name, ("(" + detail + ")" if detail else ""))


def read_cookie(page, name: str) -> str:
    cookies = {c["name"]: c["value"] for c in page.context.cookies()}
    return cookies.get(name, "")


def main() -> int:
    test_url = f"{BASE_URL}/contents-questions/index/{TEST_CONTENT_ID}"
    state_key = f"iroha.test.{USER_ID}.{TEST_CONTENT_ID}"

    with sync_playwright() as p:
        browser = p.chromium.launch()
        context = browser.new_context(viewport={"width": 1440, "height": 900})
        page = context.new_page()

        console_errors: list[str] = []
        page.on("console", lambda m: console_errors.append(m.text) if m.type == "error" else None)

        # テスト画面（index）へのリクエスト数。復元時の再読込の有無を判定する
        index_requests: list[str] = []
        page.on(
            "request",
            lambda r: index_requests.append(r.url) if "/contents-questions/index/" in r.url else None,
        )

        api = context.request
        token = ""
        assigned_here = False
        record_id = ""

        try:
            # 1. 管理者トークンを発行（受講登録の準備）
            auth = api.post(
                f"{BASE_URL}/api/v1/auth/token",
                data={"username": ADMIN_USER, "password": ADMIN_PASS, "permanent": True},
            )
            token = auth.json().get("data", {}).get("token", "") if auth.ok else ""
            check("1. API トークン発行", bool(token), f"status={auth.status}")

            headers = {"Authorization": f"Bearer {token}"} if token else {}

            # 2. 受講登録（未登録の場合のみ。テスト終了時に解除する）
            courses = api.get(f"{BASE_URL}/api/v1/users/{USER_ID}/courses", headers=headers)
            course_ids = [str(c.get("id")) for c in courses.json().get("data", [])] if courses.ok else []
            if str(COURSE_ID) in course_ids:
                print(f"    受講登録は既存（解除しない）: user_id={USER_ID} course_id={COURSE_ID}")
            else:
                res = api.post(
                    f"{BASE_URL}/api/v1/users/{USER_ID}/courses",
                    data={"course_id": int(COURSE_ID)},
                    headers=headers,
                )
                assigned_here = res.ok and res.json().get("data", {}).get("created") is True
            check("2. 受講登録（REST API）", str(COURSE_ID) in course_ids or assigned_here, f"user={USER_ID} course={COURSE_ID}")

            # 3. 受講者ログイン（フロント）
            page.goto(f"{BASE_URL}/users/login", wait_until="domcontentloaded")
            page.fill('input[name="username"]', ADMIN_USER)
            page.fill('input[name="password"]', ADMIN_PASS)
            page.click('button[type="submit"], input[type="submit"]')
            page.wait_for_load_state("domcontentloaded")
            check("3. 受講者ログイン", "users/login" not in page.url, page.url)

            # 4. テスト出題画面
            page.goto(test_url, wait_until="domcontentloaded")
            check("4. テスト出題画面", page.locator(".question").count() > 0, str(page.locator(".question").count()))

            titles_before = [t.strip() for t in page.locator(".question h2").all_text_contents()]
            qid_field = page.locator('input[name="question_id_list"]')
            qids_before = qid_field.input_value() if qid_field.count() == 1 else ""
            check("5. 出題リストが出力される", bool(qids_before), qids_before)

            # 5. 回答（各設問の先頭の選択肢を選ぶ）
            names = page.locator("input[name^='answer_']:not([disabled])").evaluate_all(
                "els => Array.from(new Set(els.map(e => e.name)))"
            )
            selected: dict[str, list[str]] = {}
            types: dict[str, str] = {}
            for name in names:
                group = page.locator(f"input[name='{name}']")
                group.first.check()
                selected[name] = sorted(group.evaluate_all("els => els.filter(e => e.checked).map(e => e.value)"))
                types[name] = group.first.get_attribute("type") or ""
            check("6. 全設問に回答", len(names) > 0 and len(selected) == len(names), f"{len(selected)}/{len(names)}")

            # 6. localStorage / Cookie への退避
            #    アプリはラジオを単一値、チェックボックスを配列で退避する
            expected_answers = {
                name: (values if types[name] == "checkbox" else (values[0] if values else None))
                for name, values in selected.items()
            }
            page.wait_for_timeout(500)
            raw = page.evaluate("(key) => localStorage.getItem(key)", state_key)
            saved = json.loads(raw) if raw else None
            check(
                "7. 回答が localStorage に退避される",
                bool(saved) and saved.get("answers") == expected_answers,
                str(saved)[:160],
            )
            check(
                "8. 退避した出題リストが一致",
                bool(saved) and ",".join(str(i) for i in saved.get("question_id_list", [])) == qids_before,
                str(saved.get("question_id_list")) if saved else "",
            )

            cookie_value = read_cookie(page, STATE_COOKIE)
            check(
                "9. 出題リストが Cookie に退避される",
                cookie_value == f"{USER_ID}-{qids_before.replace(',', '-')}",
                cookie_value[:160],
            )

            # 7. セッション切れを模擬（Cookie を破棄）
            context.clear_cookies()
            page.goto(test_url, wait_until="domcontentloaded")
            check("10. セッション切れでログイン画面へ", "users/login" in page.url, page.url)

            # 8. 再ログイン
            page.fill('input[name="username"]', ADMIN_USER)
            page.fill('input[name="password"]', ADMIN_PASS)
            page.click('button[type="submit"], input[type="submit"]')
            page.wait_for_load_state("domcontentloaded")
            check("11. 再ログイン", "users/login" not in page.url, page.url)

            # 9. テスト画面を開き直す → 回答が復元される（再読込なしの 1 リクエスト）
            index_requests.clear()
            page.goto(test_url, wait_until="domcontentloaded")
            try:
                page.wait_for_selector(".test-state-restored", timeout=15000)
                restored_notice = True
            except Exception:
                restored_notice = False
            check("12. 回答復元の通知が表示される", restored_notice)
            check(
                "13. 復元が1リクエストで完了する（再読込しない）",
                len(index_requests) == 1 and "question_id_list" not in page.url,
                f"requests={len(index_requests)} url={page.url}",
            )

            titles_after = [t.strip() for t in page.locator(".question h2").all_text_contents()]
            check("14. 復元後の出題内容が元と同じ", titles_after == titles_before, str(titles_after))

            restored = {
                name: sorted(
                    page.locator(f"input[name='{name}']").evaluate_all(
                        "els => els.filter(e => e.checked).map(e => e.value)"
                    )
                )
                for name in selected
            }
            check("15. 回答が復元される", restored == selected, str(restored))

            # 10. 採点
            page.click("input.btn-score")
            page.wait_for_selector("#confirmModal", state="visible")
            page.click("#confirmModal button.btn-score")
            page.wait_for_load_state("domcontentloaded")
            check("16. 採点結果画面へ遷移", "/contents-questions/record/" in page.url, page.url)
            if "/contents-questions/record/" in page.url:
                record_id = page.url.rstrip("/").split("/")[-1]

            titles_result = [t.strip() for t in page.locator(".question h2").all_text_contents()]
            check("17. 採点された出題内容が元と同じ", titles_result == titles_before, str(titles_result))
            score = page.locator(".result-table tr").nth(1).locator("td").nth(1).text_content().strip()
            check("18. 得点が表示される", "/" in score, score)
            print(f"    得点: {score} / record_id: {record_id or '(不明)'}")

            # 11. 採点後に退避データが削除される
            left = page.evaluate("(key) => localStorage.getItem(key)", state_key)
            check("19. 採点後に退避データが削除される", left is None, str(left)[:60])
            check(
                "20. 採点後に Cookie が削除される",
                read_cookie(page, STATE_COOKIE) == "",
                read_cookie(page, STATE_COOKIE),
            )

            # 12. コンソールエラーが無いこと
            check("21. JS コンソールエラーなし", len(console_errors) == 0, "; ".join(console_errors[:3]))
        finally:
            # 後始末: このテストで登録した受講登録のみ解除する
            if token and assigned_here:
                api.delete(f"{BASE_URL}/api/v1/users/{USER_ID}/courses/{COURSE_ID}", headers=headers)
                print(f"    受講登録を解除: user_id={USER_ID} course_id={COURSE_ID}")
            if record_id:
                print(f"    作成された学習履歴: record_id={record_id}（REST API では削除できない）")

            context.close()
            browser.close()

    failed = [r for r in results if not r[1]]
    print(f"\n=== GUI test resume: {len(results) - len(failed)}/{len(results)} PASS ===")
    if failed:
        print("FAIL した項目:", ", ".join(r[0] for r in failed))
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
