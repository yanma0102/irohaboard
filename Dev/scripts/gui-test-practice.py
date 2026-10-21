#!/usr/bin/env python3
"""
カスタム受験（練習）機能の GUI 操作テスト

仕様: Dev/Docs/design/17-custom-test.md

検証すること:
  1. REST API で受講登録（未登録の場合のみ。終了時に解除）
  2. 管理画面で「カスタム受験を許可」を ON、問題にカテゴリを設定
  3. 受講者画面のコンテンツ一覧に「練習」リンクが出る
  4. 設定画面でカテゴリ・出題数・制限時間・出題順・表示を変更して開始
  5. 指定した問題数・カテゴリ・順序で出題される
  6. 全問回答 → 採点 → 結果画面に得点・正解数・解説が出る
  7. 練習では ib_records が増えない（REST API の履歴件数）
  8. コンテンツ一覧の学習回数・理解度が変化しない
  9. 「もう一度練習」で同じ設定の設定画面に戻る
 10. 設定を変えて再実行すると、異なる出題数で開始できる
 11. 正式受験は従来どおり記録される（回帰）
 12. JS コンソールエラーが無い
 13. 後始末（カテゴリ・許可フラグを元に戻す）

必要環境:
  - Python 3.10+ / Playwright（`pip install playwright && playwright install chromium`）
  - アプリ起動（既定 http://localhost:8082）
  - 対象テストコンテンツに 3 問以上あること

使い方:
  python3 Dev/scripts/gui-test-practice.py
  BASE_URL=http://localhost:8082 API_USER=admin API_PASS=adminpass \
    USER_ID=1 COURSE_ID=4 TEST_CONTENT_ID=62 python3 Dev/scripts/gui-test-practice.py

注意:
  - 管理画面の「カスタム受験を許可」と、対象 3 問のカテゴリはテスト前の値に戻す。
  - 正式受験の回帰確認で学習履歴（ib_records）が 1 件作成される。
  - 終了コード: 全チェック PASS なら 0、1 件でも FAIL なら 1。
"""
from __future__ import annotations

import os
import re
import sys

try:
    from playwright.sync_api import sync_playwright
except ImportError:
    print("SKIP: playwright が未インストールです。pip install playwright を実行してください。")
    sys.exit(0)

BASE_URL = os.environ.get("BASE_URL", "http://localhost:8082")
ADMIN_USER = os.environ.get("API_USER", "admin")
ADMIN_PASS = os.environ.get("API_PASS", "adminpass")
USER_ID = int(os.environ.get("USER_ID", "1"))
COURSE_ID = int(os.environ.get("COURSE_ID", "4"))
TEST_CONTENT_ID = int(os.environ.get("TEST_CONTENT_ID", "62"))

PRACTICE_CATEGORY = "GUI練習テスト"
PRACTICE_QUESTION_COUNT = 3
SECOND_QUESTION_COUNT = 2
PRACTICE_TIMELIMIT = "5"

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


def records_total(api, headers: dict) -> int:
    res = api.get(
        f"{BASE_URL}/api/v1/records",
        params={"user_id": USER_ID, "content_id": TEST_CONTENT_ID, "limit": 100},
        headers=headers,
    )
    if not res.ok:
        return -1

    return int(res.json().get("meta", {}).get("total", -1))


def content_row(page):
    rows = page.locator("table.responsive-table tbody tr", has_text=CONTENT_TITLE)
    return rows.first


def read_content_metrics(page) -> tuple[str, str]:
    page.goto(f"{BASE_URL}/contents/index/{COURSE_ID}", wait_until="domcontentloaded")
    return row_metrics(content_row(page))


def row_metrics(row) -> tuple[str, str]:
    cells = row.locator("td")

    return cells.nth(5).inner_text().strip(), cells.nth(6).inner_text().strip()


def open_allow_edit(page) -> None:
    page.goto(
        f"{BASE_URL}/admin/contents/edit/{COURSE_ID}/{TEST_CONTENT_ID}",
        wait_until="domcontentloaded",
    )
    page.wait_for_selector('input[type="radio"][name="allow_custom_test"]')


def read_allow(page) -> str:
    open_allow_edit(page)

    return page.locator('input[type="radio"][name="allow_custom_test"]:checked').get_attribute("value") or ""


def save_allow(page, value: str) -> None:
    open_allow_edit(page)
    page.check(f'input[type="radio"][name="allow_custom_test"][value="{value}"]')
    page.click('button[type="submit"], input[type="submit"]')
    page.wait_for_load_state("domcontentloaded")


def open_question_edit(page, question_id: int) -> None:
    page.goto(
        f"{BASE_URL}/admin/contents-questions/edit/{TEST_CONTENT_ID}/{question_id}",
        wait_until="domcontentloaded",
    )
    page.wait_for_selector('input[name="category"]')


def read_question(page) -> dict:
    return {
        "title": page.locator('input[name="title"]').input_value(),
        "category": page.locator('input[name="category"]').input_value(),
        "correct": page.locator('input[name="correct"]').input_value(),
    }


def save_question_category(page, question_id: int, category: str) -> None:
    open_question_edit(page, question_id)
    page.fill('input[name="category"]', category)
    page.click('button[type="submit"], input[type="submit"]')
    page.wait_for_load_state("domcontentloaded")


def collect_question_ids(page) -> list[int]:
    page.goto(
        f"{BASE_URL}/admin/contents-questions/index/{TEST_CONTENT_ID}",
        wait_until="domcontentloaded",
    )
    rows = page.locator("#sortable-table tbody tr")
    ids: list[int] = []

    for i in range(rows.count()):
        button = rows.nth(i).locator("button", has_text="編集")
        if button.count() == 0:
            continue
        onclick = button.first.get_attribute("onclick") or ""
        match = re.search(r"edit/[0-9]+/([0-9]+)", onclick)
        if match:
            ids.append(int(match.group(1)))
        if len(ids) >= 3:
            break

    return ids


def answer_first_option(page) -> None:
    for i in range(page.locator(".question").count()):
        question = page.locator(".question").nth(i)
        radios = question.locator('input[type="radio"]')
        if radios.count() > 0:
            radios.first.check()
        else:
            checkboxes = question.locator('input[type="checkbox"]')
            if checkboxes.count() > 0:
                checkboxes.first.check()


def answer_wrong_option(page, wrong_values: list[int]) -> None:
    for i in range(page.locator(".question").count()):
        question = page.locator(".question").nth(i)
        wrong = wrong_values[i] if i < len(wrong_values) else 0
        radios = question.locator('input[type="radio"]')

        if radios.count() > 0:
            chosen = False
            for j in range(radios.count()):
                radio = radios.nth(j)
                if int(radio.get_attribute("value") or "0") != wrong:
                    radio.check()
                    chosen = True
                    break
            if not chosen:
                radios.first.check()
        else:
            question.locator('input[type="checkbox"]').first.check()


def submit_and_confirm(page, wait_url: str) -> None:
    page.locator("input.btn-score").first.click()
    page.wait_for_selector("#confirmModal", state="visible")
    page.locator("#confirmModal button.btn-score").click()
    page.wait_for_url(wait_url)


def main() -> int:
    global CONTENT_TITLE
    CONTENT_TITLE = ""

    with sync_playwright() as p:
        browser = p.chromium.launch()
        context = browser.new_context(viewport={"width": 1440, "height": 900})
        page = context.new_page()

        console_errors: list[str] = []
        page.on("console", lambda m: console_errors.append(m.text) if m.type == "error" else None)

        api = context.request
        token = ""
        headers: dict = {}
        assigned_here = False
        original_allow = ""
        original_questions: dict[int, dict] = {}
        record_id = ""

        try:
            # 1. 管理者トークン発行 + 受講登録
            auth = api.post(
                f"{BASE_URL}/api/v1/auth/token",
                data={"username": ADMIN_USER, "password": ADMIN_PASS, "permanent": True},
            )
            token = auth.json().get("data", {}).get("token", "") if auth.ok else ""
            headers = {"Authorization": f"Bearer {token}"} if token else {}

            courses = api.get(f"{BASE_URL}/api/v1/users/{USER_ID}/courses", headers=headers)
            course_ids = [str(c.get("id")) for c in courses.json().get("data", [])] if courses.ok else []

            if str(COURSE_ID) in course_ids:
                print(f"    受講登録は既存（解除しない）: user_id={USER_ID} course_id={COURSE_ID}")
            else:
                res = api.post(
                    f"{BASE_URL}/api/v1/users/{USER_ID}/courses",
                    data={"course_id": COURSE_ID},
                    headers=headers,
                )
                assigned_here = res.ok and res.json().get("data", {}).get("created") is True

            check(
                "1. REST API で受講登録",
                str(COURSE_ID) in course_ids or assigned_here,
                f"user={USER_ID} course={COURSE_ID}",
            )

            content_res = api.get(f"{BASE_URL}/api/v1/contents/{TEST_CONTENT_ID}", headers=headers)
            CONTENT_TITLE = content_res.json().get("data", {}).get("title", "") if content_res.ok else ""
            print(f"    対象コンテンツ: id={TEST_CONTENT_ID} title={CONTENT_TITLE!r}")

            # 2. 管理画面で許可 ON・カテゴリ設定
            login(page, ADMIN_USER, ADMIN_PASS)
            original_allow = read_allow(page)

            question_ids = collect_question_ids(page)
            print(f"    対象問題: {question_ids}")
            if len(question_ids) < 3:
                raise RuntimeError("対象テストの問題が 3 問未満です")

            for question_id in question_ids:
                open_question_edit(page, question_id)
                original_questions[question_id] = read_question(page)
                save_question_category(page, question_id, PRACTICE_CATEGORY)

            categories_ok = True
            for question_id in question_ids:
                open_question_edit(page, question_id)
                categories_ok = categories_ok and page.locator('input[name="category"]').input_value() == PRACTICE_CATEGORY

            save_allow(page, "1")
            allow_ok = read_allow(page) == "1"
            check(
                "2. 管理画面で許可 ON・カテゴリ設定",
                allow_ok and categories_ok,
                f"allow={allow_ok} categories={categories_ok}",
            )

            # 3. コンテンツ一覧の「練習」リンク
            page.goto(f"{BASE_URL}/contents/index/{COURSE_ID}", wait_until="domcontentloaded")
            row = content_row(page)
            practice_link = row.locator('a:has-text("練習")')
            check("3. コンテンツ一覧に練習リンク", practice_link.count() == 1)

            # 練習前の履歴件数・学習回数・理解度を控える
            records_before = records_total(api, headers)
            metrics_before = row_metrics(row)
            print(f"    records_before={records_before} metrics_before={metrics_before}")

            # 4. 設定画面で変更して開始
            practice_link.first.click()
            page.wait_for_url(f"**/contents-questions/practice/{TEST_CONTENT_ID}")
            page.wait_for_selector('input[name="categories[]"]')

            for checkbox in page.locator('input[name="categories[]"]').all():
                if checkbox.is_checked():
                    checkbox.uncheck()

            category_checkbox = page.locator(f'input[name="categories[]"][value="{PRACTICE_CATEGORY}"]')
            category_checkbox.check()
            page.fill('input[name="question_count"]', str(PRACTICE_QUESTION_COUNT))
            page.select_option('select[name="timelimit"]', PRACTICE_TIMELIMIT)
            page.check('input[name="order"][value="sort"]')
            page.check('input[name="wrong_mode"][value="1"]')
            page.click('button:has-text("練習を開始")')
            page.wait_for_url(f"**/contents-questions/practice-run/{TEST_CONTENT_ID}")
            check("4. 設定を変更して練習を開始", True,
                  f"count={PRACTICE_QUESTION_COUNT} timelimit={PRACTICE_TIMELIMIT} order=sort")

            # 5. 指定した問題数・カテゴリ・順序で出題
            served = page.locator(".question").count()
            served_titles = [
                page.locator(".question h2").nth(i).inner_text().strip()
                for i in range(served)
            ]
            expected_titles = [original_questions[qid]["title"] for qid in question_ids]
            check(
                "5. 指定した問題数・カテゴリ・順序で出題",
                served == PRACTICE_QUESTION_COUNT and served_titles == expected_titles,
                f"questions={served} titles={served_titles}",
            )

            # 6. 全問回答 → 採点 → 結果
            wrong_values = []
            for question_id in question_ids:
                correct = original_questions[question_id]["correct"].split(",")[0]
                wrong_values.append(int(correct) if correct.isdigit() else 0)
            answer_wrong_option(page, wrong_values)
            submit_and_confirm(page, f"**/contents-questions/practice-result/{TEST_CONTENT_ID}")

            result_text = page.locator(".contents-questions-practice-result").inner_text()
            score_ok = bool(re.search(r"0\s*/\s*30", result_text))
            count_ok = bool(re.search(r"0\s*/\s*3", result_text))
            explain_ok = "解説" in result_text
            check(
                "6. 採点結果（得点・正解数・解説）",
                score_ok and count_ok and explain_ok,
                f"score={score_ok} count={count_ok} explain={explain_ok}",
            )

            # 7. 練習では履歴が増えない
            records_after_practice = records_total(api, headers)
            records_unchanged = records_after_practice == records_before
            check(
                "7. 練習では ib_records が増えない",
                records_unchanged,
                f"before={records_before} after={records_after_practice}",
            )

            # 8. 学習回数・理解度が変化しない
            metrics_after = read_content_metrics(page)
            row_found = page.locator("table.responsive-table tbody tr", has_text=CONTENT_TITLE).count()
            check(
                "8. 学習回数・理解度が変化しない",
                row_found == 1 and metrics_after == metrics_before,
                f"rows={row_found} before={metrics_before} after={metrics_after}",
            )

            # 9. もう一度練習 → 同じ設定
            page.goto(f"{BASE_URL}/contents-questions/practice-result/{TEST_CONTENT_ID}", wait_until="domcontentloaded")
            page.click('a:has-text("もう一度練習")')
            page.wait_for_url(f"**/contents-questions/practice/{TEST_CONTENT_ID}")
            prefill_ok = (
                page.locator(f'input[name="categories[]"][value="{PRACTICE_CATEGORY}"]').is_checked()
                and page.locator('input[name="question_count"]').input_value() == str(PRACTICE_QUESTION_COUNT)
                and page.locator('select[name="timelimit"]').input_value() == PRACTICE_TIMELIMIT
                and page.locator('input[name="order"][value="sort"]').is_checked()
                and page.locator('input[name="wrong_mode"][value="1"]').is_checked()
            )
            check("9. もう一度練習で同じ設定を引き継ぐ", prefill_ok)

            # 10. 設定を変えて再実行 → 異なる出題数
            page.fill('input[name="question_count"]', str(SECOND_QUESTION_COUNT))
            page.click('button:has-text("練習を開始")')
            page.wait_for_url(f"**/contents-questions/practice-run/{TEST_CONTENT_ID}")
            second_served = page.locator(".question").count()
            check(
                "10. 設定を変えると異なる出題数で開始",
                second_served == SECOND_QUESTION_COUNT,
                f"questions={second_served} (expected {SECOND_QUESTION_COUNT})",
            )

            # 11. 正式受験は従来どおり記録される
            page.goto(f"{BASE_URL}/contents-questions/index/{TEST_CONTENT_ID}", wait_until="domcontentloaded")
            answer_first_option(page)
            submit_and_confirm(page, f"**/contents-questions/record/{TEST_CONTENT_ID}/**")
            record_id = page.url.rstrip("/").split("/")[-1]
            records_after_official = records_total(api, headers)
            check(
                "11. 正式受験は記録される",
                records_after_official == records_before + 1,
                f"before={records_before} after={records_after_official} record_id={record_id}",
            )

            # 12. JS コンソールエラーなし
            check("12. JS コンソールエラーなし", len(console_errors) == 0, "; ".join(console_errors[:3]))
        finally:
            cleanup_ok = True

            try:
                if original_allow:
                    save_allow(page, original_allow)
                    cleanup_ok = cleanup_ok and read_allow(page) == original_allow
            except Exception as e:
                cleanup_ok = False
                print(f"    許可フラグの復元に失敗: {e}")

            for question_id, original in original_questions.items():
                try:
                    save_question_category(page, question_id, original["category"])
                    open_question_edit(page, question_id)
                    cleanup_ok = cleanup_ok and page.locator('input[name="category"]').input_value() == original["category"]
                except Exception as e:
                    cleanup_ok = False
                    print(f"    カテゴリの復元に失敗 (question_id={question_id}): {e}")

            if token and assigned_here:
                api.delete(
                    f"{BASE_URL}/api/v1/users/{USER_ID}/courses/{COURSE_ID}",
                    headers=headers,
                )
                print(f"    受講登録を解除: user_id={USER_ID} course_id={COURSE_ID}")

            check("13. 後始末（カテゴリ・許可フラグ）", cleanup_ok)

            context.close()
            browser.close()

    failed = [r for r in results if not r[1]]
    print(f"\n=== GUI test practice: {len(results) - len(failed)}/{len(results)} PASS ===")
    if record_id:
        print(f"    正式受験の記録ID: {record_id}")
    if failed:
        print("FAIL した項目:", ", ".join(r[0] for r in failed))
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
