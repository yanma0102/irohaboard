<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Service;

use Cake\Core\Configure;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionInterface;
use Cake\Datasource\ConnectionManager;

/**
 * コンテンツ本文が参照する画像とコースの対応（ib_content_images）を管理する。
 *
 * 画像配信（Contents::fileImage）は「その画像を参照している公開コンテンツの
 * コースを受講できるか」で許可を判定するため、本文から画像ファイル名を抽出して
 * 保存時に参照テーブルへ展開する。
 */
class ContentImageService
{
    /**
     * @param \Cake\Datasource\ConnectionInterface $connection DB コネクション
     */
    public function __construct(
        private ConnectionInterface $connection,
    ) {
    }

    /**
     * 既定のコネクションを使うインスタンスを生成する
     *
     * @param \Cake\Datasource\ConnectionInterface|null $connection 未指定時は default
     * @return self インスタンス
     */
    public static function create(?ConnectionInterface $connection = null): self
    {
        return new self($connection ?? ConnectionManager::get('default'));
    }

    /**
     * 本文に含まれる画像ファイル名を重複なく抽出する
     *
     * Markdown 記法・HTML の img・旧形式（/uploads/...）のいずれも同じ
     * 「contents/file-image/<name>」または「uploads/<name>」から抽出する。
     *
     * @param string|null $body コンテンツ本文
     * @return array<int, string> 画像ファイル名
     */
    public function extractFileNames(?string $body): array
    {
        if ($body === null || $body === '') {
            return [];
        }

        preg_match_all('~(?:contents/file-image|uploads)/([A-Za-z0-9._-]+)~', $body, $matches);

        $allowed = array_map(
            static fn($value): string => strtolower((string)$value),
            (array)Configure::read('upload_image_extensions'),
        );

        $names = [];
        foreach ($matches[1] as $name) {
            $extension = '.' . strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($extension, $allowed, true)) {
                continue;
            }
            $names[$name] = true;
        }

        return array_keys($names);
    }

    /**
     * 1 コンテンツ分の参照関係を作り直す
     *
     * @param int $contentId コンテンツID
     * @param int $courseId 所属コースID
     * @param string|null $body コンテンツ本文
     * @return void
     */
    public function syncForContent(int $contentId, int $courseId, ?string $body): void
    {
        if ($contentId <= 0) {
            return;
        }

        $this->deleteForContent($contentId);

        foreach ($this->extractFileNames($body) as $fileName) {
            $this->db()->execute(
                'INSERT INTO ib_content_images (content_id, course_id, file_name, created)
                 VALUES (:content_id, :course_id, :file_name, :created)',
                [
                    'content_id' => $contentId,
                    'course_id' => $courseId,
                    'file_name' => $fileName,
                    'created' => date('Y-m-d H:i:s'),
                ],
            );
        }
    }

    /**
     * コンテンツに紐づく参照関係を削除する
     *
     * @param int $contentId コンテンツID
     * @return void
     */
    public function deleteForContent(int $contentId): void
    {
        $this->db()->execute(
            'DELETE FROM ib_content_images WHERE content_id = :content_id',
            ['content_id' => $contentId],
        );
    }

    /**
     * コースに紐づく参照関係を削除する（コース削除時）
     *
     * @param int $courseId コースID
     * @return void
     */
    public function deleteForCourse(int $courseId): void
    {
        $this->db()->execute(
            'DELETE FROM ib_content_images WHERE course_id = :course_id',
            ['course_id' => $courseId],
        );
    }

    /**
     * 指定ファイルを参照しているコンテンツを返す
     *
     * 参照テーブルに残骸（削除済みコンテンツ）があっても無視するため
     * ib_contents と結合する。
     *
     * @param string $fileName ファイル名
     * @return array<int, array{content_id: int, course_id: int, status: int}>
     */
    public function findReferences(string $fileName): array
    {
        $rows = $this->db()->execute(
            'SELECT ci.content_id, ci.course_id, c.status
               FROM ib_content_images ci
              INNER JOIN ib_contents c ON c.id = ci.content_id
              WHERE ci.file_name = :file_name',
            ['file_name' => $fileName],
        )->fetchAll('assoc');

        return array_map(
            static fn(array $row): array => [
                'content_id' => (int)$row['content_id'],
                'course_id' => (int)$row['course_id'],
                'status' => (int)$row['status'],
            ],
            $rows,
        );
    }

    /**
     * 全コンテンツ本文を走査し、参照されている画像ファイル名を返す
     *
     * ib_content_images の内容に依存しないため、孤児画像の判定に使える。
     *
     * @return array<int, string> ファイル名
     */
    public function scanReferencedFileNames(): array
    {
        $rows = $this->db()->execute('SELECT body FROM ib_contents')->fetchAll('assoc');

        $names = [];
        foreach ($rows as $row) {
            $body = $row['body'] === null ? null : (string)$row['body'];
            foreach ($this->extractFileNames($body) as $fileName) {
                $names[$fileName] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * 全コンテンツを走査して参照関係を作り直す
     *
     * @return int 処理したコンテンツ数
     */
    public function rebuildAll(): int
    {
        $rows = $this->db()->execute('SELECT id, course_id, body FROM ib_contents')->fetchAll('assoc');

        foreach ($rows as $row) {
            $this->syncForContent(
                (int)$row['id'],
                (int)$row['course_id'],
                $row['body'] === null ? null : (string)$row['body'],
            );
        }

        return count($rows);
    }

    /**
     * 生 SQL を実行するため Connection として扱う
     *
     * @return \Cake\Database\Connection
     */
    private function db(): Connection
    {
        /** @var \Cake\Database\Connection $connection */
        $connection = $this->connection;

        return $connection;
    }
}
