<?php
declare(strict_types=1);

/**
 * iroha Board Project
 *
 * @author        Kotaro Miura
 * @copyright     2015-2026 iroha Soft, Inc. (https://irohasoft.jp)
 */

namespace App\Mcp;

use App\Model\Table\LogsTable;
use Cake\Http\ResponseFactory;
use Cake\Http\StreamFactory;
use Cake\I18n\DateTime;
use Exception;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * MCP エンドポイント用レート制限ミドルウェア（§5.8）。
 *
 * 既存の ib_logs ベースパターン（Api\AuthController::isRateLimited）を流用し、
 * 認証済みユーザごとに計測する。
 *
 * - 読み取り（それ以外のリクエスト）: 60 リクエスト/分（log_type=mcp_request）
 * - 書き込みツール（create_content / update_content の tools/call）:
 *   20 リクエスト/分（log_type=mcp_write）
 */
class McpRateLimitMiddleware implements MiddlewareInterface
{
    /**
     * 既定の上限（読み取りリクエスト数/分）
     */
    public const DEFAULT_MAX_REQUESTS = 60;

    /**
     * 書き込みツールの上限（リクエスト数/分）
     */
    public const WRITE_MAX_REQUESTS = 20;

    /**
     * 計測ウィンドウ（秒）
     */
    public const WINDOW_SECONDS = 60;

    /**
     * ib_logs の log_type（読み取り）
     */
    public const LOG_TYPE = 'mcp_request';

    /**
     * ib_logs の log_type（書き込み）
     */
    public const WRITE_LOG_TYPE = 'mcp_write';

    /**
     * 書き込み系ツール名（このツール群の tools/call だけ書き込みカウンタ対象）
     */
    private const WRITE_TOOLS = ['create_content', 'update_content'];

    /**
     * @param \App\Model\Table\LogsTable $logsTable ログテーブル
     * @param int $maxRequests ウィンドウ内の最大リクエスト数（読み取り）
     * @param \Psr\Http\Message\ResponseFactoryInterface|null $responseFactory 429 応答生成用
     * @param int $writeMaxRequests ウィンドウ内の最大リクエスト数（書き込み）
     */
    public function __construct(
        private LogsTable $logsTable,
        private int $maxRequests = self::DEFAULT_MAX_REQUESTS,
        private ?ResponseFactoryInterface $responseFactory = null,
        private int $writeMaxRequests = self::WRITE_MAX_REQUESTS,
    ) {
    }

    /**
     * リクエストを計測し、上限超過なら 429 を返す
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request リクエスト
     * @param \Psr\Http\Server\RequestHandlerInterface $handler 次ハンドラ
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $userId = $request->getAttribute('oauth.user_id');
        if (!is_numeric($userId) || (int)$userId <= 0) {
            // 未認証は IrohaAuthMiddleware が処理する（ここには到達しない想定）
            return $handler->handle($request);
        }
        $userId = (int)$userId;

        $isWrite = $this->isWriteToolCall($request);
        $logType = $isWrite ? self::WRITE_LOG_TYPE : self::LOG_TYPE;
        $maxRequests = $isWrite ? $this->writeMaxRequests : $this->maxRequests;

        $threshold = date('Y-m-d H:i:s', time() - self::WINDOW_SECONDS);

        try {
            $count = $this->logsTable->find()
                ->where([
                    'log_type' => $logType,
                    'log_content' => (string)$userId,
                    'created >=' => $threshold,
                ])
                ->count();
        } catch (Exception $e) {
            // ログ取得失敗時は制限しない（可用性優先）
            return $handler->handle($request);
        }

        if ($count >= $maxRequests) {
            return $this->tooManyRequests();
        }

        $this->logRequest($request, $userId, $logType);

        return $handler->handle($request);
    }

    /**
     * リクエストが書き込みツールの tools/call かを判定する
     *
     * JSON-RPC 本文を読み取り、create_content / update_content の
     * 呼び出しだけを書き込みカウンタ対象とする（§5.8: 20 req/min）。
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request リクエスト
     * @return bool 書き込みツール呼び出しの場合 true
     */
    private function isWriteToolCall(ServerRequestInterface $request): bool
    {
        try {
            $stream = $request->getBody();
            $stream->rewind();
            $raw = $stream->getContents();
            $stream->rewind();

            $payload = json_decode($raw, true);
            if (!is_array($payload)) {
                return false;
            }

            return ($payload['method'] ?? null) === 'tools/call'
                && in_array($payload['params']['name'] ?? '', self::WRITE_TOOLS, true);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * リクエストを ib_logs に記録する（計測用）
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request リクエスト
     * @param int $userId ユーザID
     * @param string $logType 計測カテゴリ（self::LOG_TYPE / self::WRITE_LOG_TYPE）
     * @return void
     */
    private function logRequest(ServerRequestInterface $request, int $userId, string $logType): void
    {
        try {
            $serverParams = $request->getServerParams();
            $ip = $request->getHeaderLine('X-Forwarded-For');
            if ($ip !== '') {
                $ip = trim(explode(',', $ip)[0]);
            } else {
                $ip = (string)($serverParams['REMOTE_ADDR'] ?? '');
            }

            $log = $this->logsTable->newEntity([
                'log_type' => $logType,
                'log_content' => (string)$userId,
                'user_id' => $userId,
                'user_ip' => mb_substr($ip, 0, 50),
                'user_agent' => mb_substr($request->getHeaderLine('User-Agent'), 0, 1000),
                // LogsTable に Timestamp 行為が無いため明示設定（無いと created が NULL になり
                // ウィンドウカウント `created >=` が一切一致せずレート制限が発火しない）
                'created' => new DateTime(),
            ]);
            $this->logsTable->save($log);
        } catch (Exception $e) {
            // 記録失敗は無視（レート制限の可用性を優先）
        }
    }

    /**
     * 429 応答を生成する
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    private function tooManyRequests(): ResponseInterface
    {
        $factory = $this->responseFactory ?? new ResponseFactory();
        $body = (new StreamFactory())->createStream(
            json_encode([
                'error' => [
                    'code' => 429,
                    'message' => 'Rate limit exceeded. Try again later.',
                ],
            ], JSON_UNESCAPED_UNICODE) ?: '',
        );

        return $factory->createResponse(429)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Retry-After', (string)self::WINDOW_SECONDS)
            ->withBody($body);
    }
}
