<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Controller\InstallController;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\Http\ServerRequest;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Exception;
use ReflectionMethod;

/**
 * InstallControllerTest
 *
 * D-23: DB 名が ConnectionManager から正しく取得されること
 * D-24: SQLSTATE 23000 (UNIQUE/PRIMARY 制約違反) がエラー扱いされないこと
 * 回帰: GET /install が正常に応答すること
 */
class InstallControllerTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * @var \Cake\Database\Connection|null
     */
    private ?Connection $testConn = null;

    public function setUp(): void
    {
        parent::setUp();
        $this->testConn = ConnectionManager::get('test');
    }

    public function tearDown(): void
    {
        parent::tearDown();
    }

    // ----------------------------------------------------------------
    // ヘルパー
    // ----------------------------------------------------------------

    /**
     * テスト用の InstallController イスタンスを生成する。
     *
     * CakePHP 5 の Controller::__construct() は ServerRequest を必須とするため、
     * 最小限の ServerRequest を渡してインスタンスを生成する。
     * initialize() 内の loadComponent は IntegrationTestTrait の
     * テストコンテナによって提供されるため、ここでは生のリクエストのみ渡す。
     */
    private function makeController(): InstallController
    {
        $request = new ServerRequest([
            'url' => '/install',
            'environment' => ['REQUEST_METHOD' => 'GET'],
        ]);

        return new InstallController($request);
    }

    // ----------------------------------------------------------------
    // D-23: DB 名検出の正化
    // ----------------------------------------------------------------

    /**
     * D-23-1: ConnectionManager::getConfig('default') から DB 名が取得できること
     *
     * bootstrap.php で Configure::consume('Datasources') により Configure から除去済みでも、
     * ConnectionManager には接続設定が登録済みであるため、正しい DB 名が得られることを確認する。
     */
    public function testDatabaseNameFromConnectionManager(): void
    {
        $config = ConnectionManager::getConfig('default');
        $this->assertIsArray($config, 'ConnectionManager::getConfig(default) should return an array');
        $this->assertArrayHasKey('database', $config, 'default config should have database key');

        // テスト環境では 'irohaboard_test' が設定されているはず
        $this->assertNotEmpty($config['database'], 'database name should not be empty');
        // ハードコード 'irohaboard' にフォールバックしていないこと
        $this->assertNotSame(
            'irohaboard',
            $config['database'],
            'DB name must come from ConnectionManager, not hardcoded fallback',
        );
    }

    /**
     * D-23-2: Configure::read('Datasources') が null でも正しい DB 名が得られること
     *
     * 実際の index() メソッド内のロジックと同等の処理を検証する:
     * Configure::read('Datasources') が null でも、ConnectionManager::getConfig() から
     * 正しい DB 名が取得できること。
     */
    public function testDatabaseNameEvenWhenConfigureDatasourcesIsNull(): void
    {
        // Configure::read('Datasources') は bootstrap で consume 済みのため null。
        // null かどうかに関わらず、ConnectionManager から DB 名が得られることを検証。
        try {
            $dbConfig = ConnectionManager::getConfig('default');
            $database = $dbConfig['database'] ?? 'irohaboard';
        } catch (Exception $e) {
            $database = 'irohaboard';
        }

        $this->assertNotEmpty($database, 'database name should not be empty');
        $this->assertNotSame(
            'irohaboard',
            $database,
            'Should get actual DB name from ConnectionManager, not fallback',
        );
    }

    /**
     * D-23-3: GET /install が DB 名取得に失敗しないこと（統合テスト）
     *
     * コントローラが実際に DB 名を取得して SHOW TABLES を実行し、
     * エラーにならずにテンプレートを返すこと（installed または index）。
     */
    public function testGetInstallDoesNotFailOnDbName(): void
    {
        $this->get('/install');

        // DB 接続に失敗した場合は 500 エラーになるが、ここでは 200 が返ること
        $this->assertResponseOk();

        // installed テンプレートまたはインストールフォームのいずれかが表示されること
        $body = (string)$this->_response->getBody();
        $this->assertTrue(
            str_contains($body, 'インストール') || str_contains($body, 'installed') || str_contains($body, '<form'),
            'Response should contain install-related content',
        );
    }

    // ----------------------------------------------------------------
    // D-24: SQLSTATE 23000 の握り潰し
    // ----------------------------------------------------------------

    /**
     * D-24-1: _executeSQLScript() が 23000 エラーを無視すること（二重実行テスト）
     *
     * 同じ SQL スクリプトを2回実行した場合、1回目で正常に実行され、
     * 2回目は 42S01 (テーブル重複) と 23000 (PK/UNIQUE 重複) が無視され、
     * err_statements が空になることを確認する。
     */
    public function testExecuteSQLScriptSkipsDuplicateErrorsOnSecondRun(): void
    {
        // テスト用の一時テーブル名（衝突を避けるためユニーク名）
        $uniqueSuffix = substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
        $testTable = 'ib_test_d24_' . $uniqueSuffix;

        $sqlContent = <<<SQL
CREATE TABLE `{$testTable}` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(64) DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `{$testTable}` VALUES (1, 'test_value');

SQL;

        // 一時 SQL ファイルを作成
        $tmpFile = tempnam(sys_get_temp_dir(), 'iroha_sql_');
        $this->assertNotFalse($tmpFile, 'tempnam should succeed');
        file_put_contents($tmpFile, $sqlContent);

        try {
            $controller = $this->makeController();
            $controller->db = $this->testConn;
            $controller->path = $tmpFile;

            // Reflection で private メソッドを呼び出す
            $method = new ReflectionMethod($controller, '_executeSQLScript');

            // 1回目: CREATE TABLE + INSERT → エラーなし
            $errors1 = $method->invoke($controller);
            $this->assertEmpty($errors1, 'First run should produce no errors: ' . implode('; ', $errors1));

            // 2回目: 既存テーブル + 重複PK → 42S01 + 23000 が無視される
            $errors2 = $method->invoke($controller);
            $this->assertEmpty($errors2, 'Second run should also produce no errors (23000 and 42S01 skipped): ' . implode('; ', $errors2));
        } finally {
            // クリーンアップ: テーブル削除 + 一時ファイル削除
            try {
                $this->testConn->execute("DROP TABLE IF EXISTS `{$testTable}`");
            } catch (Exception $e) {
                // クリーンアップ失敗は無視
            }
            if (is_string($tmpFile) && file_exists($tmpFile)) {
                @unlink($tmpFile);
            }
        }
    }

    /**
     * D-24-2: _executeSQLScript() が 23000 のみを無視すること
     *
     * PRIMARY KEY 重複 INSERT のみの SQL を2回実行し、
     * 1回目は成功、2回目は 23000 が無視されて空になることを確認する。
     */
    public function testExecuteSQLScriptSkipsPrimaryKeyDuplicate(): void
    {
        $uniqueSuffix = substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
        $testTable = 'ib_test_d24_pk_' . $uniqueSuffix;

        $sqlCreate = "CREATE TABLE `{$testTable}` (`id` INT NOT NULL, `val` VARCHAR(32), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $sqlInsert1 = "INSERT INTO `{$testTable}` VALUES (1, 'first')";
        $sqlInsert2 = "INSERT INTO `{$testTable}` VALUES (1, 'duplicate_pk')";

        $tmpFile1 = null;
        $tmpFile2 = null;

        try {
            $controller = $this->makeController();
            $controller->db = $this->testConn;

            // 1回目のスクリプト: CREATE + INSERT
            $tmpFile1 = tempnam(sys_get_temp_dir(), 'iroha_sql_');
            $this->assertNotFalse($tmpFile1, 'tempnam should succeed');
            file_put_contents($tmpFile1, $sqlCreate . ";\n" . $sqlInsert1 . ";\n");
            $controller->path = $tmpFile1;
            $method = new ReflectionMethod($controller, '_executeSQLScript');
            $errors1 = $method->invoke($controller);
            $this->assertEmpty($errors1, 'First run (CREATE + INSERT) should succeed: ' . implode('; ', $errors1));

            // 2回目のスクリプト: INSERT 重複 (23000)
            $tmpFile2 = tempnam(sys_get_temp_dir(), 'iroha_sql_');
            $this->assertNotFalse($tmpFile2, 'tempnam should succeed');
            file_put_contents($tmpFile2, $sqlInsert2 . ";\n");
            $controller->path = $tmpFile2;
            $errors2 = $method->invoke($controller);
            $this->assertEmpty($errors2, 'Second run (duplicate PK) should be skipped via 23000: ' . implode('; ', $errors2));
        } finally {
            if (is_string($tmpFile1) && file_exists($tmpFile1)) {
                @unlink($tmpFile1);
            }
            if (is_string($tmpFile2) && file_exists($tmpFile2)) {
                @unlink($tmpFile2);
            }
            try {
                $this->testConn->execute("DROP TABLE IF EXISTS `{$testTable}`");
            } catch (Exception $e) {
                // ignore
            }
        }
    }

    /**
     * D-24-3: _executeSQLScript() が 23000 以外の genuine エラーは捕捉すること
     *
     * 構文エラーなど 23000 以外のエラーは従来通り err_statements に含まれることを確認する。
     */
    public function testExecuteSQLScriptStillCapturesNonIgnoredErrors(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'iroha_sql_');
        $this->assertNotFalse($tmpFile, 'tempnam should succeed');
        file_put_contents($tmpFile, "THIS IS NOT VALID SQL;\n");

        try {
            $controller = $this->makeController();
            $controller->db = $this->testConn;
            $controller->path = $tmpFile;

            $method = new ReflectionMethod($controller, '_executeSQLScript');
            $errors = $method->invoke($controller);
            $this->assertNotEmpty($errors, 'Invalid SQL should still produce errors');
        } finally {
            if (is_string($tmpFile) && file_exists($tmpFile)) {
                @unlink($tmpFile);
            }
        }
    }

    // ----------------------------------------------------------------
    // D-34: インストールフォームが CSRF トークンを送出すること
    // ----------------------------------------------------------------

    /**
     * D-34: インストールフォームに _csrfToken の hidden    input が存在すること
     *
     * /install は CsrfProtectionMiddleware の除外パスに含まれないため、
     * トークンを送らない POST は 403 で拒否される。
     * templates/Install/index.php は生 HTML のフォーム（FormHelper を使わない）なので、
     * hidden input を明示的に埋め込む必要がある。
     *
     * テスト DB には ib_users が存在するため GET /install は installed テンプレートを
     * 返す（＝フォームをレンダリングしない）。そのためテンプレートを直接検査する。
     */
    public function testInstallFormEmbedsCsrfToken(): void
    {
        $templatePath = ROOT . DS . 'templates' . DS . 'Install' . DS . 'index.php';
        $this->assertFileExists($templatePath, 'install テンプレートが存在すること');

        $source = (string)file_get_contents($templatePath);

        $this->assertStringContainsString(
            'name="_csrfToken"',
            $source,
            'D-34: インストールフォームに _csrfToken の hidden input が必要です（無いと POST が 403）',
        );
        $this->assertStringContainsString(
            "getAttribute('csrfToken')",
            $source,
            'D-34: トークンはリクエスト属性 csrfToken から取得してください',
        );
    }

    // ----------------------------------------------------------------
    // D-35: POST されたフォーム値が正しく読み取られること
    // ----------------------------------------------------------------

    /**
     * D-35: getData('data.User.*') で送信値が取得できること
     *
     * テンプレートの input name は data[User][username] だが、CakePHP 5 の
     * ServerRequest::getData() はトップレベルのキー（'data'）を起点に解釈するため、
     * 'User.username' では一致せず常に既定値 '' になっていた（インストール永久不能）。
     *
     * 実際の index() と同じ取得ロジックを検証する。
     */
    public function testFormDataIsReadFromDataUserKey(): void
    {
        $request = new ServerRequest([
            'url' => '/install',
            'environment' => ['REQUEST_METHOD' => 'POST'],
            'post' => [
                'data' => [
                    'User' => [
                        'username' => 'installadmin',
                        'password' => 'installpass',
                        'password2' => 'installpass',
                    ],
                ],
            ],
        ]);

        // 旧実装（'User.username'）では NULL になることを確認する（回帰検知の前提）
        $this->assertNull(
            $request->getData('User.username'),
            'CakePHP 5 では getData("User.username") は解決されない（D-35 の原因）',
        );

        // 修正後: 'data.User' 経由で取得できる
        $userData = (array)$request->getData('data.User', []);
        $this->assertSame('installadmin', $userData['username'] ?? null);
        $this->assertSame('installpass', $userData['password'] ?? null);
        $this->assertSame('installpass', $userData['password2'] ?? null);
    }

    /**
     * D-35: index() が data[User][...] を 'User.xxx' として参照していないこと
     *
     * D-35 と同様の読み取り方（'User.username' など）が残っていればインストールが
     * 永久に完了しないため、ソースレベルで回帰を防ぐ。
     */
    public function testIndexDoesNotReadUnprefixedUserDataKeys(): void
    {
        $source = (string)file_get_contents(
            ROOT . DS . 'src' . DS . 'Controller' . DS . 'InstallController.php',
        );

        $this->assertStringNotContainsString(
            "getData('User.",
            $source,
            'D-35: getData("User.username") 等の生キーはCakePHP 5 で解決されません。data.User を使用してください',
        );
        $this->assertStringContainsString(
            "getData('data.User'",
            $source,
            'D-35: data.User 経由でフォーム値を読み取る必要があります',
        );
    }

    // ----------------------------------------------------------------
    // 回帰テスト
    // ----------------------------------------------------------------

    /**
     * GET /install が正常にレスポンスを返すこと（インストール済み判定含む）
     *
     * テスト DB に ib_users テーブルが存在するため、installed テンプレートが返されること。
     */
    public function testGetInstallReturnsProperResponse(): void
    {
        $this->get('/install');
        $this->assertResponseOk();
    }
}
