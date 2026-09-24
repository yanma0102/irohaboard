<?php
declare(strict_types=1);

namespace App\Test\TestCase\Migration;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * 移行（Migration）の整合性検証テスト
 *
 * B-2: ib_settings のデフォルト値が正しいことを確認
 * B-3: 移行後のテーブル構造が正しいことを確認
 */
class MigrationVerificationTest extends TestCase
{
    /**
     * setUp
     */
    public function setUp(): void
    {
        parent::setUp();
    }

    /**
     * 全 16 テーブルが存在することを確認（B-3）
     */
    public function testAllTablesExist(): void
    {
        $expectedTables = [
            'ib_cake_sessions',
            'ib_contents',
            'ib_contents_questions',
            'ib_courses',
            'ib_groups',
            'ib_groups_courses',
            'ib_infos',
            'ib_infos_groups',
            'ib_logs',
            'ib_records',
            'ib_records_questions',
            'ib_settings',
            'ib_user_tokens',
            'ib_users',
            'ib_users_courses',
            'ib_users_groups',
        ];

        $result = ConnectionManager::get('test')->execute(
            "SHOW TABLES LIKE 'ib_%'"
        )->fetchAll('assoc');

        $actualTables = array_column($result, 'Tables_in_' . $this->getTestDbName() . ' (ib_%)');
        if (empty($actualTables)) {
            $actualTables = array_values($result[0] ?? []);
        }

        sort($expectedTables);
        sort($actualTables);

        $this->assertEquals(
            $expectedTables,
            $actualTables,
            '移行後のテーブル一覧が期待値と一致しません'
        );
    }

    /**
     * ib_users テーブルのカラム構造を確認（B-3）
     */
    public function testUsersTableColumns(): void
    {
        $this->assertTableHasColumns('ib_users', [
            'id', 'username', 'password', 'name', 'role', 'email',
            'comment', 'last_logined', 'started', 'ended',
            'created', 'modified', 'deleted',
        ]);
    }

    /**
     * ib_courses テーブルのカラム構造を確認（B-3）
     */
    public function testCoursesTableColumns(): void
    {
        $this->assertTableHasColumns('ib_courses', [
            'id', 'title', 'introduction', 'opened', 'created', 'modified',
            'deleted', 'sort_no', 'comment', 'user_id',
        ]);
    }

    /**
     * ib_contents テーブルのカラム構造を確認（B-3）
     */
    public function testContentsTableColumns(): void
    {
        $this->assertTableHasColumns('ib_contents', [
            'id', 'course_id', 'user_id', 'title', 'url', 'file_name',
            'kind', 'body', 'timelimit', 'pass_rate', 'question_count',
            'wrong_mode', 'status', 'opened', 'created', 'modified',
            'deleted', 'sort_no', 'comment',
        ]);
    }

    /**
     * ib_contents_questions テーブルのカラム構造を確認（B-3）
     */
    public function testContentsQuestionsTableColumns(): void
    {
        $this->assertTableHasColumns('ib_contents_questions', [
            'id', 'content_id', 'question_type', 'title', 'body', 'image',
            'options', 'correct', 'score', 'explain', 'comment',
            'created', 'modified', 'sort_no',
        ]);
    }

    /**
     * ib_records テーブルのカラム構造を確認（B-3）
     */
    public function testRecordsTableColumns(): void
    {
        $this->assertTableHasColumns('ib_records', [
            'id', 'course_id', 'user_id', 'content_id', 'full_score',
            'pass_score', 'score', 'is_passed', 'is_complete', 'progress',
            'understanding', 'study_sec', 'created',
        ]);
    }

    /**
     * ib_records_questions テーブルのカラム構造を確認（B-3）
     */
    public function testRecordsQuestionsTableColumns(): void
    {
        $this->assertTableHasColumns('ib_records_questions', [
            'id', 'record_id', 'question_id', 'answer', 'correct',
            'is_correct', 'score', 'created',
        ]);
    }

    /**
     * ib_groups テーブルのカラム構造を確認（B-3）
     */
    public function testGroupsTableColumns(): void
    {
        $this->assertTableHasColumns('ib_groups', [
            'id', 'title', 'comment', 'created', 'modified',
            'deleted', 'status', 'logo', 'copyright', 'module',
        ]);
    }

    /**
     * ib_settings テーブルのカラム構造を確認（B-3）
     */
    public function testSettingsTableColumns(): void
    {
        $this->assertTableHasColumns('ib_settings', [
            'id', 'setting_key', 'setting_name', 'setting_value',
        ]);
    }

    /**
     * ib_user_tokens テーブルのカラム構造を確認（B-3）
     */
    public function testUserTokensTableColumns(): void
    {
        $this->assertTableHasColumns('ib_user_tokens', [
            'id', 'user_id', 'token_type', 'token_selector', 'token_hash',
            'expired', 'last_used', 'revoked', 'user_ip', 'user_agent',
            'created', 'modified',
        ]);
    }

    /**
     * ib_infos テーブルのカラム構造を確認（B-3）
     */
    public function testInfosTableColumns(): void
    {
        $this->assertTableHasColumns('ib_infos', [
            'id', 'title', 'body', 'opened', 'closed', 'created', 'modified', 'user_id',
        ]);
    }

    /**
     * ib_logs テーブルのカラム構造を確認（B-3）
     */
    public function testLogsTableColumns(): void
    {
        $this->assertTableHasColumns('ib_logs', [
            'id', 'log_type', 'log_content', 'user_id', 'user_ip', 'user_agent', 'created',
        ]);
    }

    /**
     * 外部キー制約が正しく設定されていることを確認（B-3）
     *
     * Migrator は addForeignKey() を使用しないため、
     * テーブル間のリレーション整合性を ORM 側で検証する。
     */
    public function testForeignKeyIntegrity(): void
    {
        $this->seedDefaultSettings();

        $usersTable = $this->getTableLocator()->get('Users');
        $coursesTable = $this->getTableLocator()->get('Courses');
        $contentsTable = $this->getTableLocator()->get('Contents');
        $recordsTable = $this->getTableLocator()->get('Records');

        // リレーションが正しく設定されていることを確認
        $this->assertTrue($usersTable->hasAssociation('Groups'), 'Users → Groups リレーションがない');
        $this->assertTrue($coursesTable->hasAssociation('Contents'), 'Courses → Contents リレーションがない');
        $this->assertTrue($contentsTable->hasAssociation('Courses'), 'Contents → Courses リレーションがない');
        $this->assertTrue($recordsTable->hasAssociation('RecordsQuestions'), 'Records → RecordsQuestions リレーションがない');
    }

    /**
     * B-2: ib_settings のデフォルト値が正しいことを確認
     *
     * Migrator はスキーマ作成のみ。設定データは手動シードする。
     * 以前 updated_1789960516 に破損した実績があるため、
     * シード後のデフォルト値が正しいことを検証する。
     */
    public function testSettingsDefaultValues(): void
    {
        $this->seedDefaultSettings();

        $settingsTable = $this->getTableLocator()->get('Settings');

        $titleSetting = $settingsTable->find()
            ->where(['setting_key' => 'title'])
            ->first();

        $this->assertNotNull($titleSetting, 'title 設定が存在しません');
        $this->assertEquals(
            'eラーニングシステム',
            $titleSetting->setting_value,
            'B-2: title のデフォルト値が破損しています（updated_1789960516 等）'
        );
    }

    /**
     * B-2: 全設定キーが存在することを確認
     */
    public function testSettingsAllKeysExist(): void
    {
        $this->seedDefaultSettings();

        $settingsTable = $this->getTableLocator()->get('Settings');

        $expectedKeys = ['title', 'copyright', 'color', 'information'];
        $actualKeys = $settingsTable->find('list', [
            'keyField' => 'setting_key',
            'valueField' => 'setting_key',
        ])->toArray();

        sort($expectedKeys);
        sort($actualKeys);

        $this->assertEquals(
            $expectedKeys,
            $actualKeys,
            '設定キーのリストが期待値と一致しません'
        );
    }

    /**
     * B-2: 各設定値が空文字でないことを確認
     */
    public function testSettingsValuesNotEmpty(): void
    {
        $this->seedDefaultSettings();

        $settingsTable = $this->getTableLocator()->get('Settings');

        $settings = $settingsTable->find()
            ->select(['setting_key', 'setting_value'])
            ->toArray();

        $this->assertNotEmpty($settings, '設定が1件も存在しません');

        foreach ($settings as $setting) {
            $this->assertNotEmpty(
                $setting->setting_value,
                "B-2: 設定キー '{$setting->setting_key}' の値が空文字です"
            );
        }
    }

    /**
     * インデックスが正しく設定されていることを確認（B-3）
     */
    public function testIndexesExist(): void
    {
        $db = $this->getTestDbName();
        $result = ConnectionManager::get('test')->execute(
            "SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME "
            . "FROM INFORMATION_SCHEMA.STATISTICS "
            . "WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME LIKE 'ib_%' "
            . "AND INDEX_NAME != 'PRIMARY' "
            . "ORDER BY TABLE_NAME, INDEX_NAME"
        )->fetchAll('assoc');

        $this->assertNotEmpty($result, 'インデックスが1つも存在しません');

        $indexMap = [];
        foreach ($result as $row) {
            $key = $row['TABLE_NAME'] . '.' . $row['INDEX_NAME'];
            $indexMap[$key][] = $row['COLUMN_NAME'];
        }

        $this->assertArrayHasKey('ib_users.login_id', $indexMap, 'ib_users の unique インデックス login_id がない');
        $this->assertArrayHasKey('ib_records.idx_course_user_content_id', $indexMap, 'ib_records の複合インデックスがない');
        $this->assertArrayHasKey('ib_user_tokens.uk_token_selector', $indexMap, 'ib_user_tokens の unique インデックスがない');
    }

    /**
     * テーブルの文字コードが utf8mb4 であることを確認（B-3）
     */
    public function testTableCharsetUtf8mb4(): void
    {
        $db = $this->getTestDbName();
        $result = ConnectionManager::get('test')->execute(
            "SELECT TABLE_NAME, TABLE_COLLATION "
            . "FROM INFORMATION_SCHEMA.TABLES "
            . "WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME LIKE 'ib_%'"
        )->fetchAll('assoc');

        $this->assertNotEmpty($result);

        foreach ($result as $row) {
            $this->assertStringStartsWith(
                'utf8mb4_',
                $row['TABLE_COLLATION'],
                "{$row['TABLE_NAME']} の文字コードが utf8mb4 ではありません（{$row['TABLE_COLLATION']}）"
            );
        }
    }

    /**
     * テーブルのカラム一覧を取得してアサーション
     */
    private function assertTableHasColumns(string $table, array $expectedColumns): void
    {
        $result = ConnectionManager::get('test')->execute(
            "SHOW COLUMNS FROM `{$table}`"
        )->fetchAll('assoc');

        $actualColumns = array_column($result, 'Field');
        sort($expectedColumns);
        sort($actualColumns);

        $this->assertEquals(
            $expectedColumns,
            $actualColumns,
            "{$table} のカラム構造が期待値と一致しません"
        );
    }

    /**
     * Settings テーブルに本番デフォルト値をシード
     *
     * Migrator はスキーマ作成のみ。設定データは手動で挿入する。
     * 本番 `tests/schema.sql` の INSERT と同じ 4 件。
     */
    private function seedDefaultSettings(): void
    {
        $settingsTable = $this->getTableLocator()->get('Settings');
        $settingsTable->deleteAll('1 = 1');
        $records = [
            ['setting_key' => 'title', 'setting_name' => 'システム名', 'setting_value' => 'eラーニングシステム'],
            ['setting_key' => 'copyright', 'setting_name' => 'コピーライト', 'setting_value' => 'Copyright (C) 2016-2026 XXX Co.,Ltd. All rights reserved.'],
            ['setting_key' => 'color', 'setting_name' => 'テーマカラー', 'setting_value' => '#337ab7'],
            ['setting_key' => 'information', 'setting_name' => 'お知らせ', 'setting_value' => 'テストお知らせ'],
        ];
        foreach ($records as $record) {
            $entity = $settingsTable->newEntity($record);
            $settingsTable->save($entity);
        }
    }

    /**
     * テストデータベース名を取得
     */
    private function getTestDbName(): string
    {
        $config = ConnectionManager::get('test')->config();
        return $config['database'] ?? 'irohaboard_test';
    }
}
