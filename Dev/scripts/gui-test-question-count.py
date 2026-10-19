#!/usr/bin/env python3
"""
出題数（question_count）の変更がテスト出題へ反映されることの GUI 操作テスト

仕様: Dev/Docs/design/16-test-answer-resume.md（出題リストの決定）

検証すること:
  1. 管理画面で出題数を全問（問題数以上）に設定すると全問が出題される
  2. 出題数を全問より小さい値に変更すると、変更後の出題数だけ出題される
     （同じセッションで開き直した場合も、セッションに残った変更前の
       出題リストを使わず再抽選される＝本不具合の回帰）
  3. 新しいセッションでも変更後の出題数だけ出題される
  4. 出題数が元の値に戻せること／JS コンソールエラーが無いこと

必要環境:
  - Python 3.10+ / Playwright（`pip install playwright && playwright install chromium`）
  - アプリ起動（既定 http://localhost:8082）
  - 対象テストコンテンツの問題数が `QUESTION_COUNT_FEW` より多いこと

使い方:
  python3 Dev/scripts/gui-test-question-count.py
  BASE_URL=http://localhost:8082 API_USER=admin API_PASS=adminpass \
    USER_ID=1 COURSE_ID=4 TEST_CONTENT_ID=62 python3 Dev/scripts/gui-test-question-count.py

注意:
  - 受講登録は REST API で行う。テスト前に未登録だった場合のみ、終了時に解除する。
  - 管理画面の出題数はテスト前の値に戻す（途中で失敗した場合も finally で戻す）。
  - 終了コード: 全チェック PASS なら 0、1 件でも FAIL なら 1。
"""
from __future__ import annotations

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
COURSE_ID = os.environ.get("COURSE_ID", "4")
TEST_CONTENT_ID = os.environ.get("TEST_CONTENT_ID", "62")

# 問題数以上を指定して全問出題させるための値（出題数の上限は 100）
QUESTION_COUNT_ALL = 100
# 全問より小さい出題数（問題数がこれより多いコンテンツを対象にする）
QUESTION_COUNT_FEW = 5

results: list[tuple[str, bool, str]] = []


def check(name: str, ok: bool, detail: str = "") -> None:
    results.append((name, ok, detail))
    print(("PASS" if ok else "FAIL"), "-", name, ("(" + detail + ")" if detail else ""))


def login(page, user: str, password: str) -> None:
    page.goto(f"{BASE_URL}/users/login", wait_until="domcontentloaded")
    page.fill('input[name="username"]', user)
    page.fill('input[name="password"]', password)
    page.click('button[type="submit"], input[type="submit"]')
    page.wait_for_load_state("domcontentloaded")


def edit_url() -> str:
    return f"{BASE_URL}/admin/contents/edit/{COURSE_ID}/{TEST_CONTENT_ID}"


def open_edit(page) -> None:
    page.goto(edit_url(), wait_until="domcontentloaded")
    page.wait_for_selector('input[name="question_count"]')


def read_question_count(page) -> str:
    open_edit(page)

    return page.locator('input[name="question_count"]').input_value()


def save_question_count(page, value: str) -> None:
    open_edit(page)
    page.fill('input[name="question_count"]', value)
    page.click('button[type="submit"], input[type="submit"]')
    page.wait_for_load_state("domcontentloaded")


def open_test(page) -> int:
    page.goto(f"{BASE_URL}/contents-questions/index/{TEST_CONTENT_ID}", wait_until="domcontentloaded")
    page.wait_for_timeout(300)

    return page.locator(".question").count()


def main() -> int:
    with sync_playwright() as p:
        browser = p.chromium.launch()
        context = browser.new_context(viewport={"width": 1440, "height": 900})
        page = context.new_page()

        console_errors: list[str] = []
        page.on("console", lambda m: console_errors.append(m.text) if m.type == "error" else None)

        api = context.request
        token = ""
        assigned_here = False
        original_count = ""
        changed = False

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
            check(
                "2. 受講登録（REST API）",
                str(COURSE_ID) in course_ids or assigned_here,
                f"user={USER_ID} course={COURSE_ID}",
            )

            # 3. 管理者ログイン（管理画面と受講者画面を同一セッションで操作）
            login(page, ADMIN_USER, ADMIN_PASS)
            check("3. 管理画面ログイン", "users/login" not in page.url, page.url)

            # 4. テスト前の出題数を控える
            original_count = read_question_count(page)
            check("4. 現在の出題数を取得", True, f"question_count={original_count or '(未設定)'}")

            # 5. 出題数を全問（問題数以上）に設定
            changed = True
            save_question_count(page, str(QUESTION_COUNT_ALL))
            saved = read_question_count(page)
            check("5. 出題数（全問）の保存", saved == str(QUESTION_COUNT_ALL), saved)

            # 6. 全問が出題される（セッションに全問の出題リストが保存される）
            total = open_test(page)
            check(
                "6. 全問が出題される",
                total > QUESTION_COUNT_FEW,
                f"questions={total} (> {QUESTION_COUNT_FEW})",
            )

            # 7. 出題数を全問より小さい値に変更
            save_question_count(page, str(QUESTION_COUNT_FEW))
            saved = read_question_count(page)
            check("7. 出題数（変更後）の保存", saved == str(QUESTION_COUNT_FEW), saved)

            # 8. 同じセッションで開き直す → 変更後の出題数だけ出題される（本不具合の回帰）
            served_same = open_test(page)
            check(
                "8. 同じセッションで変更後の出題数だけ出題される",
                served_same == QUESTION_COUNT_FEW,
                f"questions={served_same} (expected {QUESTION_COUNT_FEW})",
            )

            # 9. 新しいセッションでも変更後の出題数だけ出題される
            context2 = browser.new_context(viewport={"width": 1440, "height": 900})
            page2 = context2.new_page()
            page2.on("console", lambda m: console_errors.append(m.text) if m.type == "error" else None)
            login(page2, ADMIN_USER, ADMIN_PASS)
            served_fresh = open_test(page2)
            check(
                "9. 新しいセッションでも変更後の出題数だけ出題される",
                served_fresh == QUESTION_COUNT_FEW,
                f"questions={served_fresh} (expected {QUESTION_COUNT_FEW})",
            )
            context2.close()

            # 10. 出題数を元の値に戻す
            save_question_count(page, original_count)
            restored = read_question_count(page)
            changed = False
            check("10. 出題数を元の値に戻す", restored == original_count, f"question_count={restored or '(未設定)'}")

            # 11. コンソールエラーが無いこと
            check("11. JS コンソールエラーなし", len(console_errors) == 0, "; ".join(console_errors[:3]))
        finally:
            # 後始末: 出題数が戻っていない場合は元の値に戻す
            if changed:
                try:
                    if read_question_count(page) != original_count:
                        save_question_count(page, original_count)
                        print(f"    出題数を復元: question_count={original_count or '(未設定)'}")
                except Exception as e:
                    print(f"    出題数の復元に失敗: {e}")

            # 後始末: このテストで登録した受講登録のみ解除する
            if token and assigned_here:
                api.delete(f"{BASE_URL}/api/v1/users/{USER_ID}/courses/{COURSE_ID}", headers=headers)
                print(f"    受講登録を解除: user_id={USER_ID} course_id={COURSE_ID}")

            context.close()
            browser.close()

    failed = [r for r in results if not r[1]]
    print(f"\n=== GUI test question-count: {len(results) - len(failed)}/{len(results)} PASS ===")
    if failed:
        print("FAIL した項目:", ", ".join(r[0] for r in failed))
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
