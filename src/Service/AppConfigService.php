<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 * @link          https://irohaboard.irohasoft.jp
 * @license       https://www.gnu.org/licenses/gpl-3.0.en.html GPL License
 */

namespace App\Service;

use Cake\Core\Configure;
use Cake\ORM\Locator\TableLocator;

/**
 * アプリ設定管理サービス
 *
 * config/ib_config_schema.php のスキーマに基づき、設定値の
 * 検証・正規化・キャスト・保存・リセット・bootstrap適用を行う。
 */
class AppConfigService
{
    /**
     * スキーマキャッシュ
     *
     * @var array|null
     */
    private static ?array $schemaCache = null;

    /**
     * カテゴリキャッシュ
     *
     * @var array|null
     */
    private static ?array $categoriesCache = null;

    /**
     * スキーマを取得する
     *
     * @return array {'categories': array, 'fields': array}
     */
    public static function schema(): array
    {
        if (self::$schemaCache !== null) {
            return self::$schemaCache['fields'] ?? self::$schemaCache;
        }

        $configPath = defined('CONFIG')
            ? CONFIG
            : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR;

        $config = require $configPath . 'ib_config_schema.php';
        self::$schemaCache = $config['fields'] ?? $config;

        return self::$schemaCache;
    }

    /**
     * カテゴリ定義を取得する
     *
     * @return array カテゴリID => ['label' => ..., 'order' => ...]
     */
    public static function categories(): array
    {
        if (self::$categoriesCache !== null) {
            return self::$categoriesCache;
        }

        $configPath = defined('CONFIG')
            ? CONFIG
            : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR;

        $config = require $configPath . 'ib_config_schema.php';
        self::$categoriesCache = $config['categories'] ?? [];

        return self::$categoriesCache;
    }

    /**
     * 指定カテゴリに属する設定キー一覧を取得する
     *
     * @param string $category カテゴリID
     * @return array<int, string> 設定キーの配列
     */
    public static function keysByCategory(string $category): array
    {
        $fields = self::schema();
        $keys = [];

        foreach ($fields as $key => $definition) {
            if ($definition['category'] === $category) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * 入力データを検証する
     *
     * @param array $data 受信データ（キー => 値）
     * @param string|null $category カテゴリID（省略時はスキーマ全キーを検証）
     * @return array キー => エラーメッセージ（空なら検証成功）
     */
    public static function validate(array $data, ?string $category = null): array
    {
        $fields = self::schema();
        $errors = [];

        foreach ($fields as $key => $definition) {
            // カテゴリ指定時はそのカテゴリのキーのみ検証
            if ($category !== null && $definition['category'] !== $category) {
                continue;
            }

            $value = $data[$key] ?? null;

            // required_if チェック
            if (isset($definition['required_if'])) {
                [$refKey, $refValue] = $definition['required_if'];
                $refRaw = $data[$refKey] ?? Configure::read($refKey);

                // bool 型の参照値は '1'/'0' で比較
                if ($definition['required_if'][1] === true) {
                    $refMatch = ($refRaw === '1' || $refRaw === true || $refRaw === 'true');
                } else {
                    $refMatch = ((string)$refRaw === (string)$refValue);
                }

                if ($refMatch && ($value === null || $value === '')) {
                    $errors[$key] = 'このフィールドは必須です。';
                    continue;
                }
            }

            // 空値でrequired_if未発動の場合、他のチェックをスキップ（secretの空=変更なし）
            if ($value === null || $value === '') {
                continue;
            }

            // 型別チェック
            switch ($definition['type']) {
                case 'bool':
                    if (!in_array($value, ['0', '1', 0, 1], true)) {
                        $errors[$key] = 'ブール値（0 または 1）を指定してください。';
                    }
                    break;

                case 'int':
                    if (!is_numeric($value) || (string)(int)$value !== (string)$value) {
                        $errors[$key] = '整数値を指定してください。';
                        break;
                    }
                    $intVal = (int)$value;
                    if (isset($definition['min']) && $intVal < $definition['min']) {
                        $errors[$key] = sprintf('%d 以上の値を指定してください。', $definition['min']);
                    } elseif (isset($definition['max']) && $intVal > $definition['max']) {
                        $errors[$key] = sprintf('%d 以下の値を指定してください。', $definition['max']);
                    }
                    break;

                case 'size':
                    if (!is_numeric($value) || (int)$value <= 0) {
                        $errors[$key] = '正の整数値（MB）を指定してください。';
                        break;
                    }
                    $intVal = (int)$value;
                    if (isset($definition['min']) && $intVal < $definition['min']) {
                        $errors[$key] = sprintf('%d MB 以上の値を指定してください。', $definition['min']);
                    } elseif (isset($definition['max']) && $intVal > $definition['max']) {
                        $errors[$key] = sprintf('%d MB 以下の値を指定してください。', $definition['max']);
                    }
                    break;

                case 'string':
                case 'secret':
                    // 特別な検証なし（空は上記でスキップ済み）
                    break;
            }
        }

        return $errors;
    }

    /**
     * 入力データを正規化する（DB保存可能な形式へ変換）
     *
     * - bool  → '1'/'0'
     * - int   → 10進文字列
     * - size  → バイト値（文字列）
     * - string/secret → トリム文字列
     *
     * @param array $data 受信データ（キー => 値）
     * @return array キー => 正規化規化済み値
     */
    public static function normalize(array $data): array
    {
        $fields = self::schema();
        $normalized = [];

        foreach ($data as $key => $value) {
            // スキーマに存在しないキーは無視
            if (!isset($fields[$key])) {
                continue;
            }

            $type = $fields[$key]['type'];

            switch ($type) {
                case 'bool':
                    $normalized[$key] = ($value === '1' || $value === 1 || $value === true) ? '1' : '0';
                    break;

                case 'int':
                    $normalized[$key] = (string)(int)$value;
                    break;

                case 'size':
                    // MB → バイト（×1048576）
                    $normalized[$key] = (string)((int)$value * 1048576);
                    break;

                case 'string':
                case 'secret':
                default:
                    $normalized[$key] = trim((string)$value);
                    break;
            }
        }

        return $normalized;
    }

    /**
     * DBに保存された値をPHP型にキャストする
     *
     * Configure::write に適した型で返す。
     *
     * @param string $key 設定キー
     * @param string $rawValue DB上の生の値
     * @return mixed キャスト済みの値
     */
    public static function cast(string $key, string $rawValue): mixed
    {
        $fields = self::schema();

        if (!isset($fields[$key])) {
            return $rawValue;
        }

        $type = $fields[$key]['type'];

        switch ($type) {
            case 'bool':
                return filter_var($rawValue, FILTER_VALIDATE_BOOLEAN);

            case 'int':
            case 'size':
                return (int)$rawValue;

            case 'string':
            case 'secret':
            default:
                return (string)$rawValue;
        }
    }

    /**
     * DBオーバーライドを Configure に適用する（bootstrap 呼び出し用）
     *
     * 1. ib_config.php のデフォルト値スナップショットを保存
     * 2. ib_config_overrides テーブルから全オーバーライドを取得
     * 3. キーごとに型キャストして Configure::write
     *
     * DB未接続・テーブル未作成等の例外は全て握り潰し、
     * ファイル値のみで起動を継続する。
     *
     * @return void
     */
    public static function applyOverrides(): void
    {
        try {
            $fields = self::schema();

            // 1. デフォルト値スナップショット（ib_config.php 読み込み直後の値）
            $defaults = [];
            foreach ($fields as $key => $definition) {
                $defaults[$key] = Configure::read($key);
            }
            Configure::write('ib_config_defaults', $defaults);

            // 2. DBからオーバーライドを取得
            $tableLocator = new TableLocator();
            $configOverridesTable = $tableLocator->get('ConfigOverrides');
            $overrides = $configOverridesTable->getOverrides();

            // 3. キーごとにキャストして上書き
            foreach ($overrides as $key => $rawValue) {
                if (!isset($fields[$key])) {
                    continue;
                }
                $typedValue = self::cast($key, $rawValue);
                Configure::write($key, $typedValue);
            }
        } catch (\Throwable) {
            // DB未接続・テーブル未作成等の例外を握り潰し、ファイル値で起動継続
        }
    }

    /**
     * カテゴリごとに設定値を保存する
     *
     * 検証 → 正規化 → upsert の順で処理する。
     * secret 型の値が空文字の場合は既存値を維持（保存スキップ）。
     *
     * @param array $data 受信データ（キー => 値）
     * @param string $category カテゴリID
     * @return void
     * @throws \RuntimeException バリデーションエラー時
     */
    public static function saveCategory(array $data, string $category): void
    {
        // 検証
        $errors = self::validate($data, $category);
        if (!empty($errors)) {
            $messages = implode('; ', $errors);
            throw new \RuntimeException("バリデーションエラー: {$messages}");
        }

        // 正規化
        $normalized = self::normalize($data);

        // 対象キーのみ抽出
        $targetKeys = self::keysByCategory($category);

        // DBへ保存
        $tableLocator = new TableLocator();
        $configOverridesTable = $tableLocator->get('ConfigOverrides');

        $fields = self::schema();
        $defaults = Configure::read('ib_config_defaults') ?: [];

        // 複数キーをまとめて書き換えるためトランザクションで囲む
        // （途中で失敗すると「一部だけ保存された」中途半端な状態になるため）
        $connection = $configOverridesTable->getConnection();
        $connection->transactional(function () use ($targetKeys, $normalized, $configOverridesTable, $fields, $defaults): void {
            foreach ($targetKeys as $key) {
                if (!isset($normalized[$key])) {
                    continue;
                }

                $value = $normalized[$key];

                // secret 型の空文字は保存スキップ（既存値を維持）
                if ($fields[$key]['type'] === 'secret' && $value === '') {
                    continue;
                }

                // 既定値と一致する場合はオーバーライド行を残さない
                // （保存しても「上書き中」表示は出ないため、行だけが残ると混乱を招く）
                if (isset($defaults[$key]) && (string)$defaults[$key] === $value) {
                    $configOverridesTable->deleteOverride($key);
                    continue;
                }

                $configOverridesTable->saveOverride($key, $value);
            }
        });
    }

    /**
     * カテゴリの設定値をデフォルトに戻す（オーバーライドを削除）
     *
     * @param string $category カテゴリID
     * @return void
     */
    public static function resetCategory(string $category): void
    {
        $targetKeys = self::keysByCategory($category);

        if (empty($targetKeys)) {
            return;
        }

        try {
            $tableLocator = new TableLocator();
            $configOverridesTable = $tableLocator->get('ConfigOverrides');
            $configOverridesTable->deleteByCategory($targetKeys);
        } catch (\Throwable) {
            // DB例外は無視（未インストール環境など）
        }
    }
}
