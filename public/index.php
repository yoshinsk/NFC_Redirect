<?php
/**
 * ファイル: public/index.php
 * 機能: NFC URLの識別子をDBで解決し、解析を記録してから登録済みの転送先へ302リダイレクトする。
 */

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

/**
 * 公開URLのエラーは、登録済みかどうかの詳細を出さない共通メッセージにする。
 */
function nfc_public_error(int $status): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    $message = $status === 503 ? 'ただいま転送先を確認できません。時間をおいて再度お試しください。' : '指定されたNFC URLは利用できません。';
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>転送先を確認できません</title></head><body><main><h1>転送先を確認できません</h1><p>' . nfc_h($message) . '</p></main></body></html>';
    exit;
}

$requestPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
$slug = trim($requestPath, '/');

if (!preg_match('/\A[a-z0-9][a-z0-9_-]{7,63}\z/', $slug)) {
    nfc_public_error(404);
}

try {
    $pdo = nfc_pdo();
    $lookup = $pdo->prepare('SELECT id, destination_url FROM redirects WHERE slug = :slug LIMIT 1');
    $lookup->execute([':slug' => $slug]);
    $redirect = $lookup->fetch();

    if (!is_array($redirect)) {
        nfc_public_error(404);
    }

    try {
        nfc_record_redirect($pdo, (int) $redirect['id']);
    } catch (Throwable $exception) {
        error_log('nfc_redirect_event_write_failed redirect_id=' . (int) $redirect['id']);
    }

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Referrer-Policy: no-referrer');
    header('Location: ' . (string) $redirect['destination_url'], true, 302);
    exit;
} catch (Throwable $exception) {
    error_log('nfc_redirect_lookup_failed');
    nfc_public_error(503);
}
