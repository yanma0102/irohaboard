<?php
declare(strict_types=1);

namespace App\Controller\Api;

use Cake\Http\Exception\HttpException;

/**
 * API エラー例外
 *
 * fail() からスローされ、ApiErrorMiddleware で JSON レスポンスに変換される
 */
class ApiException extends HttpException
{
    private array $errorPayload;

    public function __construct(int $statusCode, string $message, $errors = null)
    {
        $error = [
            'code' => $statusCode,
            'message' => $message,
        ];

        if ($errors !== null && $errors !== []) {
            $error['errors'] = $errors;
        }

        $this->errorPayload = ['error' => $error];

        parent::__construct($message, $statusCode);
    }

    /**
     * @return array
     */
    public function getErrorPayload(): array
    {
        return $this->errorPayload;
    }
}
