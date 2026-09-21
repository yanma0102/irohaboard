<?php
declare(strict_types=1);

/**
 * iroha Board REST API 基底コントローラ
 *
 * CakePHP 5 版
 *
 * 認証: Authorization: Bearer <selector:validator>
 * 応答: {"data": ...} / {"data": [...], "meta": {...}} / {"error": {"code","message"}}
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 * @link          https://irohaboard.irohasoft.jp
 * @license       https://www.gnu.org/licenses/gpl-3.0.en.html GPL License
 */

namespace App\Controller\Api;

use App\Controller\AppController;
use Cake\Http\Exception\HttpException;

/**
 * ApiBase Controller
 */
class ApiBaseController extends AppController
{
    /**
     * API では不要なコンポーネントをロードしない
     *
     * @return void
     */
    public function initialize(): void
    {
        // AppController::initialize() は呼ばない（FormProtection / Flash をロードしない）
        $this->loadComponent('Authentication.Authentication');

        $this->autoRender = false;
    }

    /**
     * 認証不要でアクセス可能なアクション名
     *
     * @var array<string>
     */
    protected array $allowUnauthenticated = [];

    /**
     * 認証済みユーザ情報（password を除く）
     *
     * @var array|null
     */
    protected ?array $apiUser = null;

    /**
     * 使用中トークンのID
     *
     * @var int|null
     */
    protected ?int $apiTokenId = null;

    /**
     * 使用中のトークン文字列
     *
     * @var string|null
     */
    protected ?string $apiToken = null;

    /**
     * コールバック（アクションロジック実行前に実行）
     *
     * @param \Cake\Event\EventInterface $event
     * @return void
     */
    public function beforeFilter(\Cake\Event\EventInterface $event): ?\Cake\Http\Response
    {
        parent::beforeFilter($event);

        $this->response = $this->response->withType('application/json');

        $action = $this->request->getParam('action');
        if (in_array($action, $this->allowUnauthenticated, true)) {
            return null;
        }

        $this->authenticateApiRequest();

        return null;
    }

    /**
     * リクエストヘッダから Authorization を取得する
     *
     * @return string
     */
    protected function getAuthorizationHeader(): string
    {
        $authorization = $this->request->getHeaderLine('Authorization');
        if ($authorization !== '') {
            return trim($authorization);
        }

        return '';
    }

    /**
     * Bearer トークンによる認証
     *
     * @return void
     */
    protected function authenticateApiRequest(): void
    {
        $header = $this->getAuthorizationHeader();

        if ($header === '') {
            $this->fail(401, 'Authorization header is missing');
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            $this->fail(401, 'Authorization header must use the Bearer scheme');
        }

        $token = trim($matches[1]);

        $userTokensTable = $this->fetchTable('UserTokens');
        $result = $userTokensTable->authenticateApiToken($token);

        if (!$result || empty($result['user'])) {
            $this->fail(401, 'Invalid or expired API token');
        }

        $this->apiUser = $result['user'];
        $this->apiTokenId = isset($result['token_id']) ? (int)$result['token_id'] : null;
        $this->apiToken = $token;
    }

    /**
     * リクエストボディ（JSON もしくはフォーム）を連想配列で取得する
     *
     * @return array
     */
    protected function input(): array
    {
        $data = $this->request->getData();

        if (empty($data)) {
            $raw = (string)$this->request->getBody();

            if ($raw !== '') {
                $decoded = json_decode($raw, true);

                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }

        return is_array($data) ? $data : [];
    }

    /**
     * クエリパラメータを取得する（空文字は null 扱い）
     *
     * @param string $key パラメータ名
     * @return string|null
     */
    protected function queryParam(string $key): ?string
    {
        $value = $this->request->getQuery($key);

        if ($value === null || $value === '') {
            return null;
        }

        return (string)$value;
    }

    /**
     * 文字列フィルタを完全一致で行うか（?exact=1）
     *
     * @return bool
     */
    protected function wantsExact(): bool
    {
        $exact = $this->request->getQuery('exact');

        if ($exact === null || $exact === '') {
            return false;
        }

        return filter_var($exact, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * JSON レスポンスを構築する
     *
     * @param array $payload レスポンス内容
     * @param int $status HTTPステータスコード
     * @return \Cake\Http\Response
     */
    protected function respond(array $payload, int $status = 200): \Cake\Http\Response
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            $json = json_encode(['error' => ['code' => 500, 'message' => 'Failed to encode response']]);
            $status = 500;
        }

        $this->response = $this->response
            ->withStatus($status)
            ->withType('application/json')
            ->withStringBody($json);

        return $this->response;
    }

    /**
     * 単一データの成功レスポンス
     *
     * @param mixed $data データ
     * @param int $status HTTPステータスコード
     * @return \Cake\Http\Response
     */
    protected function ok(mixed $data, int $status = 200): \Cake\Http\Response
    {
        return $this->respond(['data' => $data], $status);
    }

    /**
     * 一覧データの成功レスポンス
     *
     * @param array $rows 一覧
     * @param array $meta メタ情報
     * @param int $status HTTPステータスコード
     * @return \Cake\Http\Response
     */
    protected function okList(array $rows, array $meta = [], int $status = 200): \Cake\Http\Response
    {
        return $this->respond([
            'data' => array_values($rows),
            'meta' => $meta,
        ], $status);
    }

    /**
     * エラーレスポンスを送信して終了する
     *
     * @param int $status HTTPステータスコード
     * @param string $message エラーメッセージ
     * @param mixed $errors 詳細（バリデーションエラー等）
     * @return \Cake\Http\Response
     */
    protected function fail(int $status, string $message, $errors = null): \Cake\Http\Response
    {
        $error = [
            'code' => (int)$status,
            'message' => (string)$message,
        ];

        if ($errors !== null && $errors !== []) {
            $error['errors'] = $errors;
        }

        return $this->respond(['error' => $error], $status);
    }

    /**
     * 認証済みユーザIDを取得する
     *
     * @return int
     */
    protected function currentUserId(): int
    {
        return (int)($this->apiUser['id'] ?? 0);
    }

    /**
     * 認証済みユーザのロールを取得する
     *
     * @return string
     */
    protected function currentRole(): string
    {
        return (string)($this->apiUser['role'] ?? '');
    }

    /**
     * 管理系ロールかどうか
     *
     * @return bool
     */
    protected function isStaff(): bool
    {
        return in_array($this->currentRole(), ['admin', 'manager', 'editor', 'teacher'], true);
    }

    /**
     * 管理系ロールを要求する
     *
     * @return void
     */
    protected function requireStaff(): void
    {
        if (!$this->isStaff()) {
            $this->fail(403, 'This action requires a staff role');
        }
    }

    /**
     * 管理者／マネージャ権限を要求する
     *
     * @return void
     */
    protected function requireManager(): void
    {
        if (!in_array($this->currentRole(), ['admin', 'manager'], true)) {
            $this->fail(403, 'This action requires an admin or manager role');
        }
    }

    /**
     * ページングパラメータを取得する
     *
     * @return array{page: int, limit: int, offset: int}
     */
    protected function pagination(): array
    {
        $page = (int)$this->request->getQuery('page');
        if ($page < 1) {
            $page = 1;
        }

        $limit = (int)$this->request->getQuery('limit');
        if ($limit < 1) {
            $limit = 50;
        }
        if ($limit > 200) {
            $limit = 200;
        }

        return [
            'page' => $page,
            'limit' => $limit,
            'offset' => ($page - 1) * $limit,
        ];
    }

    /**
     * 一覧を取得する（ページング付き）
     *
     * @param \Cake\ORM\Table $table 対象テーブル
     * @param array $findOptions find オプション（conditions / fields / order）
     * @return array{0: array, 1: array} [rows, meta]
     */
    protected function paginatedList(\Cake\ORM\Table $table, array $findOptions = []): array
    {
        $paging = $this->pagination();
        $conditions = $findOptions['conditions'] ?? [];

        $total = (int)$table->find()->where($conditions)->count();

        $query = $table->find()
            ->where($conditions)
            ->limit($paging['limit'])
            ->offset($paging['offset']);

        if (isset($findOptions['fields'])) {
            $query->select($findOptions['fields']);
        }
        if (isset($findOptions['order'])) {
            $query->order($findOptions['order']);
        }

        $rows = [];

        if ($total > 0) {
            foreach ($query->all() as $row) {
                $rows[] = $row->toArray();
            }
        }

        $meta = [
            'page' => $paging['page'],
            'limit' => $paging['limit'],
            'total' => $total,
            'count' => count($rows),
        ];

        return [$rows, $meta];
    }

    /**
     * 指定ユーザが受講可能なコースIDを取得する
     *
     * @param int $userId ユーザID
     * @return array<int> コースIDの配列
     */
    protected function accessibleCourseIds(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $connection = $this->fetchTable('Users')->getConnection();

        $sql = <<<EOF
SELECT course_id
  FROM ib_users_courses
 WHERE user_id = :user_id
UNION
SELECT gc.course_id
  FROM ib_groups_courses gc
 INNER JOIN ib_users_groups ug ON gc.group_id = ug.group_id
 WHERE ug.user_id = :user_id
EOF;

        $rows = $connection->execute($sql, ['user_id' => $userId])->fetchAll('assoc');

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int)$row['course_id'];
        }

        return array_values(array_unique($ids));
    }

    /**
     * 指定ユーザが所属するグループIDを取得する
     *
     * @param int $userId ユーザID
     * @return array<int> グループIDの配列
     */
    protected function currentUserGroupIds(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $connection = $this->fetchTable('Users')->getConnection();

        $sql = "SELECT group_id FROM ib_users_groups WHERE user_id = :user_id";
        $rows = $connection->execute($sql, ['user_id' => $userId])->fetchAll('assoc');

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int)$row['group_id'];
        }

        return array_values(array_unique($ids));
    }
}
