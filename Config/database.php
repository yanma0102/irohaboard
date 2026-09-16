<?php
class DATABASE_CONFIG
{
	// iroha Board で使用するデータベース
	// 環境変数（DB_HOST / DB_USER / DB_PASS / DB_NAME）が設定されている場合は
	// そちらを優先します（Docker 環境用）。未設定の場合は従来どおりの値を使用します。
	public $default = [
		'datasource' => 'Database/Mysql', // 変更しないでください
		'persistent' => true,
		'host' => 'localhost', // MySQLサーバのホスト名
		'login' => 'root', // ユーザ名
		'password' => '', // パスワード
		'database' => 'irohaboard', // データベース名
		'prefix' => 'ib_', // 変更しないでください
		'encoding' => 'utf8'
	];

	public function __construct()
	{
		$env = function ($key, $default) {
			$value = getenv($key);
			return ($value === false || $value === '') ? $default : $value;
		};

		$this->default['host'] = $env('DB_HOST', $this->default['host']);
		$this->default['login'] = $env('DB_USER', $this->default['login']);
		$this->default['password'] = $env('DB_PASS', $this->default['password']);
		$this->default['database'] = $env('DB_NAME', $this->default['database']);
	}
}
