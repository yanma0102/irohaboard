<?php
/**
 * iroha Board REST API 基底コントローラ
 *
 * 既存の AppController（Auth/Security/Session/ACL/Setting 依存）は使わず、
 * CakePHP の Controller を直接継承して API 専用の認証・レスポンスを提供する。
 *
 * 認証: Authorization: Bearer <selector:validator>
 * 応答: {"data": ...} / {"data": [...], "meta": {...}} / {"error": {"code","message"}}
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 * @link          https://irohaboard.irohasoft.jp
 * @license       https://www.gnu.org/licenses/gpl-3.0.en.html GPL License
 */

App::uses('Controller', 'Controller');
App::uses('ClassRegistry', 'Utility');

class ApiBaseController extends Controller
{
	/**
	 * API ではコンポーネント（Auth/Security/Session 等）を使用しない
	 * @var array
	 */
	public $components = [];

	/**
	 * 使用モデル（サブクラスで指定）
	 * @var array
	 */
	public $uses = [];

	/**
	 * ビューは使用せず JSON を直接返す
	 * @var bool
	 */
	public $autoRender = false;

	/**
	 * 認証不要でアクセス可能なアクション名
	 * @var array
	 */
	protected $allowUnauthenticated = [];

	/**
	 * 認証済みユーザ情報（password を除く）
	 * @var array|null
	 */
	protected $apiUser = null;

	/**
	 * 使用中トークンのID
	 * @var int|null
	 */
	protected $apiTokenId = null;

	/**
	 * 使用中のトークン文字列
	 * @var string|null
	 */
	protected $apiToken = null;

	/**
	 * コールバック（アクションロジック実行前に実行）
	 *
	 * @return void
	 */
	public function beforeFilter()
	{
		parent::beforeFilter();

		$this->response->type('application/json');

		if(in_array($this->action, $this->allowUnauthenticated, true))
			return;

		$this->authenticateApiRequest();
	}

	/**
	 * リクエストヘッダから Authorization を取得する
	 *
	 * @return string
	 */
	protected function getAuthorizationHeader()
	{
		if(!empty($_SERVER['HTTP_AUTHORIZATION']))
			return trim($_SERVER['HTTP_AUTHORIZATION']);

		if(!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']))
			return trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);

		if(function_exists('apache_request_headers'))
		{
			$headers = apache_request_headers();

			if(is_array($headers))
			{
				foreach($headers as $key => $value)
				{
					if(strcasecmp($key, 'Authorization') === 0)
						return trim($value);
				}
			}
		}

		return '';
	}

	/**
	 * Bearer トークンによる認証
	 *
	 * @return void
	 */
	protected function authenticateApiRequest()
	{
		$header = $this->getAuthorizationHeader();

		if($header === '')
			$this->fail(401, 'Authorization header is missing');

		if(!preg_match('/^Bearer\s+(.+)$/i', $header, $matches))
			$this->fail(401, 'Authorization header must use the Bearer scheme');

		$token = trim($matches[1]);

		$UserToken = ClassRegistry::init('UserToken');
		$result = $UserToken->authenticateApiToken($token);

		if(!$result || empty($result['user']))
			$this->fail(401, 'Invalid or expired API token');

		$this->apiUser = $result['user'];
		$this->apiTokenId = isset($result['token_id']) ? $result['token_id'] : null;
		$this->apiToken = $token;
	}

	/**
	 * リクエストボディ（JSON もしくはフォーム）を連想配列で取得する
	 *
	 * @return array
	 */
	protected function input()
	{
		$data = $this->request->data;

		if(empty($data))
		{
			$raw = $this->request->input();

			if(is_string($raw) && $raw !== '')
			{
				$decoded = json_decode($raw, true);

				if(is_array($decoded))
					$data = $decoded;
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
	protected function queryParam($key)
	{
		$value = $this->request->query($key);

		if($value === null || $value === '')
			return null;

		return $value;
	}

	/**
	 * 文字列フィルタを完全一致で行うか（?exact=1）
	 *
	 * @return bool
	 */
	protected function wantsExact()
	{
		$exact = $this->request->query('exact');

		if($exact === null || $exact === '')
			return false;

		return filter_var($exact, FILTER_VALIDATE_BOOLEAN);
	}

	/**
	 * JSON レスポンスを構築する
	 *
	 * @param array $payload レスポンス内容
	 * @param int $status HTTPステータスコード
	 * @return CakeResponse
	 */
	protected function respond($payload, $status = 200)
	{
		try
		{
			$this->response->statusCode($status);
		}
		catch(Exception $e)
		{
			// CakePHP 2 の CakeResponse は一部のステータスコード（422 等）を
			// 許可しないため、未対応コードは 400 に丸める
			$status = 400;
			$this->response->statusCode($status);
		}

		$this->response->type('application/json');

		$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		if($json === false)
			$json = json_encode(['error' => ['code' => 500, 'message' => 'Failed to encode response']]);

		$this->response->body($json);

		return $this->response;
	}

	/**
	 * 単一データの成功レスポンス
	 *
	 * @param mixed $data データ
	 * @param int $status HTTPステータスコード
	 * @return CakeResponse
	 */
	protected function ok($data, $status = 200)
	{
		return $this->respond(['data' => $data], $status);
	}

	/**
	 * 一覧データの成功レスポンス
	 *
	 * @param array $rows 一覧
	 * @param array $meta メタ情報
	 * @param int $status HTTPステータスコード
	 * @return CakeResponse
	 */
	protected function okList($rows, $meta = [], $status = 200)
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
	 * @return void
	 */
	protected function fail($status, $message, $errors = null)
	{
		$error = [
			'code' => (int)$status,
			'message' => (string)$message,
		];

		if($errors !== null && $errors !== [])
			$error['errors'] = $errors;

		$this->respond(['error' => $error], $status);
		$this->response->send();
		$this->_stop();
	}

	/**
	 * 認証済みユーザIDを取得する
	 *
	 * @return int
	 */
	protected function currentUserId()
	{
		return (int)$this->apiUser['id'];
	}

	/**
	 * 認証済みユーザのロールを取得する
	 *
	 * @return string
	 */
	protected function currentRole()
	{
		return (string)$this->apiUser['role'];
	}

	/**
	 * 管理系ロールかどうか
	 *
	 * @return bool
	 */
	protected function isStaff()
	{
		return in_array($this->currentRole(), ['admin', 'manager', 'editor', 'teacher'], true);
	}

	/**
	 * 管理系ロールを要求する
	 *
	 * @return void
	 */
	protected function requireStaff()
	{
		if(!$this->isStaff())
			$this->fail(403, 'This action requires a staff role');
	}

	/**
	 * 管理者／マネージャ権限を要求する
	 *
	 * @return void
	 */
	protected function requireManager()
	{
		if(!in_array($this->currentRole(), ['admin', 'manager'], true))
			$this->fail(403, 'This action requires an admin or manager role');
	}

	/**
	 * ページングパラメータを取得する
	 *
	 * @return array {page, limit, offset}
	 */
	protected function pagination()
	{
		$page = (int)$this->request->query('page');
		if($page < 1)
			$page = 1;

		$limit = (int)$this->request->query('limit');
		if($limit < 1)
			$limit = 50;
		if($limit > 200)
			$limit = 200;

		return [
			'page' => $page,
			'limit' => $limit,
			'offset' => ($page - 1) * $limit,
		];
	}

	/**
	 * 一覧を取得する（ページング付き）
	 *
	 * @param Model $model 対象モデル
	 * @param array $findOptions find オプション（conditions / fields / order）
	 * @return array [rows, meta]
	 */
	protected function paginatedList(Model $model, array $findOptions = [])
	{
		$paging = $this->pagination();
		$conditions = isset($findOptions['conditions']) ? $findOptions['conditions'] : [];

		$total = (int)$model->find('count', ['conditions' => $conditions]);

		$options = $findOptions;
		$options['limit'] = $paging['limit'];
		$options['offset'] = $paging['offset'];

		if(!isset($options['order']))
			$options['order'] = [$model->alias . '.' . $model->primaryKey => 'asc'];

		$rows = [];

		if($total > 0)
		{
			$result = $model->find('all', $options);

			foreach($result as $row)
				$rows[] = $row[$model->alias];
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
	 * users_courses に登録されているコース、もしくは所属グループに紐づくコース
	 *
	 * @param int $userId ユーザID
	 * @return array コースIDの配列
	 */
	protected function accessibleCourseIds($userId)
	{
		$userId = (int)$userId;

		if($userId <= 0)
			return [];

		$Course = ClassRegistry::init('Course');

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

		$rows = $Course->query($sql, ['user_id' => $userId]);

		return $this->_collectIds($rows, 'course_id');
	}

	/**
	 * 指定ユーザが所属するグループIDを取得する
	 *
	 * @param int $userId ユーザID
	 * @return array グループIDの配列
	 */
	protected function currentUserGroupIds($userId)
	{
		$userId = (int)$userId;

		if($userId <= 0)
			return [];

		$Group = ClassRegistry::init('Group');

		$sql = "SELECT group_id FROM ib_users_groups WHERE user_id = :user_id";
		$rows = $Group->query($sql, ['user_id' => $userId]);

		return $this->_collectIds($rows, 'group_id');
	}

	/**
	 * query() の結果から指定カラムのIDを重複なく取り出す
	 *
	 * @param array $rows クエリ結果
	 * @param string $field カラム名
	 * @return array
	 */
	protected function _collectIds($rows, $field)
	{
		$ids = [];

		if(!is_array($rows))
			return $ids;

		foreach($rows as $row)
		{
			if(!is_array($row))
				continue;

			foreach($row as $record)
			{
				if(is_array($record) && isset($record[$field]))
					$ids[] = (int)$record[$field];
			}
		}

		return array_values(array_unique($ids));
	}
}
