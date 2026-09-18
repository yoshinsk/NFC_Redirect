<?php
/**
 * ファイル: app/bootstrap.php
 * 機能: 公開リダイレクト画面と管理画面で共通利用する設定、DB、認証、CSRF、入力検証の基盤。
 */

declare(strict_types=1);

/**
 * 本番サーバーの公開ディレクトリ外に置く設定ファイルを安全に読み込む。
 * 設定不足時に詳細をブラウザへ出さず、管理者が配備漏れを発見できるよう例外化する。
 *
 * @return array<string, mixed>
 */
function nfc_config(): array
{
    static $config = null;

    if (is_array($config)) {
        return $config;
    }

    $privateDirectory = dirname(__DIR__) . '/private';
    $configPath = is_file($privateDirectory . '/config.local.php')
        ? $privateDirectory . '/config.local.php'
        : $privateDirectory . '/config.php';
    if (!is_file($configPath)) {
        throw new RuntimeException('アプリケーション設定が見つかりません。');
    }

    $loaded = require $configPath;
    if (!is_array($loaded)) {
        throw new RuntimeException('アプリケーション設定の形式が不正です。');
    }

    $config = $loaded;
    return $config;
}

/**
 * MariaDB 接続を1リクエスト中で再利用する。
 * 例外メッセージには接続文字列やパスワードを含めず、呼び出し元で利用者向けの一般的なエラーへ変換する。
 */
function nfc_pdo(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $database = nfc_config()['database'] ?? [];
    if (!is_array($database)) {
        throw new RuntimeException('データベース設定の形式が不正です。');
    }

    $host = (string) ($database['host'] ?? 'localhost');
    $port = (int) ($database['port'] ?? 3306);
    $name = (string) ($database['name'] ?? '');
    $user = (string) ($database['user'] ?? '');
    $password = (string) ($database['password'] ?? '');

    if ($name === '' || $user === '') {
        throw new RuntimeException('データベース設定が不足しています。');
    }

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $pdo;
}

/**
 * 管理画面・解析画面のすべての可変表示値をHTMLエスケープする。
 */
function nfc_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * NFC URLを常に設定済みの正規ホスト名で組み立てる。
 */
function nfc_public_url(string $slug): string
{
    $baseUrl = rtrim((string) (nfc_config()['app']['base_url'] ?? ''), '/');
    return $baseUrl . '/' . rawurlencode($slug) . '/';
}

/**
 * 管理画面のセッションをHTTPS専用かつJavaScriptから非参照のCookieで開始する。
 * セッション固定化を防ぐため、ログイン成功時には別途IDを再生成する。
 */
function nfc_start_admin_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $timeout = (int) (nfc_config()['app']['admin_session_timeout'] ?? 1800);
    session_name('nfc_admin_session');
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/admin/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    if (empty($_SESSION['login_csrf_token'])) {
        $_SESSION['login_csrf_token'] = bin2hex(random_bytes(32));
    }

    if (isset($_SESSION['authenticated_at'], $_SESSION['last_activity'])) {
        if ((time() - (int) $_SESSION['last_activity']) > $timeout) {
            nfc_logout();
            return;
        }

        $_SESSION['last_activity'] = time();
    }
}

/**
 * 現在のセッションが有効な管理者ログイン済み状態かを返す。
 */
function nfc_is_admin_authenticated(): bool
{
    return isset($_SESSION['authenticated_at'], $_SESSION['last_activity']);
}

/**
 * ログイン失敗をセッション単位で短時間制限する。
 * 共有管理アカウントのため、詳細な失敗理由は返さず総当たり試行の速度を抑制する。
 */
function nfc_login_is_rate_limited(): bool
{
    $now = time();
    $attempts = $_SESSION['login_attempts'] ?? [];
    $attempts = array_values(array_filter(
        is_array($attempts) ? $attempts : [],
        static fn (mixed $attempt): bool => is_int($attempt) && $attempt > ($now - 900)
    ));
    $_SESSION['login_attempts'] = $attempts;

    return count($attempts) >= 5;
}

/**
 * 設定ファイル中のパスワードハッシュと照合し、成功時だけログイン状態を確立する。
 */
function nfc_attempt_login(string $username, string $password): bool
{
    if (nfc_login_is_rate_limited()) {
        return false;
    }

    $admin = nfc_config()['admin'] ?? [];
    $expectedUsername = (string) ($admin['username'] ?? '');
    $passwordHash = (string) ($admin['password_hash'] ?? '');
    $valid = $expectedUsername !== ''
        && $passwordHash !== ''
        && hash_equals($expectedUsername, $username)
        && password_verify($password, $passwordHash);

    if (!$valid) {
        $_SESSION['login_attempts'][] = time();
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['authenticated_at'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['login_attempts'] = [];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    return true;
}

/**
 * 管理セッションを完全に破棄し、ログアウト後に古いCookieを再利用できないようにする。
 */
function nfc_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $parameters = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $parameters['path'] ?? '/admin/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/**
 * 未認証の管理操作をサインイン画面へ戻す。
 */
function nfc_require_admin(): void
{
    if (nfc_is_admin_authenticated()) {
        return;
    }

    header('Location: /admin/?action=login', true, 303);
    exit;
}

/**
 * 状態変更リクエストを同一セッション発行のCSRFトークンで検証する。
 */
function nfc_verify_csrf(): void
{
    $token = (string) ($_POST['csrf_token'] ?? '');
    $expected = (string) ($_SESSION['csrf_token'] ?? '');
    if ($token === '' || $expected === '' || !hash_equals($expected, $token)) {
        http_response_code(403);
        exit('リクエストを確認できませんでした。画面を再読み込みしてからやり直してください。');
    }
}

/**
 * 未認証状態のサインインPOSTを、その画面表示時に発行したトークンで検証する。
 */
function nfc_verify_login_csrf(): void
{
    $token = (string) ($_POST['login_csrf_token'] ?? '');
    $expected = (string) ($_SESSION['login_csrf_token'] ?? '');
    if ($token === '' || $expected === '' || !hash_equals($expected, $token)) {
        http_response_code(403);
        exit('リクエストを確認できませんでした。画面を再読み込みしてからやり直してください。');
    }
}

/**
 * 管理画面のPOST完了後に一度だけ表示する通知を保持する。
 */
function nfc_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/**
 * 一度だけ表示する通知を取り出す。
 *
 * @return array{type: string, message: string}|null
 */
function nfc_pull_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    if (!is_array($flash) || !isset($flash['type'], $flash['message'])) {
        return null;
    }

    return ['type' => (string) $flash['type'], 'message' => (string) $flash['message']];
}

/**
 * 推測に強いランダム値から、NFC URLに使える小文字の識別子を生成する。
 */
function nfc_generate_slug(): string
{
    return bin2hex(random_bytes(16));
}

/**
 * 作成・編集フォームの値を検証し、DBに保存してよい正規化済みの値と項目別エラーを返す。
 *
 * @return array{data: array{slug: string, destination_url: string}, errors: array<string, string>}
 */
function nfc_validate_redirect_input(array $input): array
{
    $slug = strtolower(trim((string) ($input['slug'] ?? '')));
    $destinationUrl = trim((string) ($input['destination_url'] ?? ''));
    $errors = [];

    if (!preg_match('/\A[a-z0-9][a-z0-9_-]{7,63}\z/', $slug)) {
        $errors['slug'] = '＊識別子は英小文字・数字・ハイフン・アンダースコアで8〜64文字にしてください。';
    }

    $urlParts = filter_var($destinationUrl, FILTER_VALIDATE_URL) ? parse_url($destinationUrl) : false;
    $scheme = is_array($urlParts) ? strtolower((string) ($urlParts['scheme'] ?? '')) : '';
    $host = is_array($urlParts) ? (string) ($urlParts['host'] ?? '') : '';
    $hasCredentials = is_array($urlParts) && (isset($urlParts['user']) || isset($urlParts['pass']));

    if (
        $destinationUrl === ''
        || strlen($destinationUrl) > 2048
        || !in_array($scheme, ['http', 'https'], true)
        || $host === ''
        || $hasCredentials
        || str_contains($destinationUrl, "\r")
        || str_contains($destinationUrl, "\n")
    ) {
        $errors['destination_url'] = '＊転送先には、認証情報を含まない http または https の完全なURLを入力してください。';
    }

    return [
        'data' => ['slug' => $slug, 'destination_url' => $destinationUrl],
        'errors' => $errors,
    ];
}

/**
 * 解析ログへ記録してよい値だけに正規化する。
 * HTTP認証、Cookie、トークン、パスワード等に見えるキーは値を保存せず、過大なヘッダは切り詰める。
 *
 * @return array<string, string>
 */
function nfc_sanitize_request_variables(array $variables): array
{
    $sanitized = [];

    foreach ($variables as $key => $value) {
        $name = (string) $key;
        if (preg_match('/authorization|cookie|password|passwd|secret|token|api[_-]?key|session|credential/i', $name)) {
            $sanitized[$name] = '[REDACTED]';
            continue;
        }

        if (is_array($value) || is_object($value) || is_resource($value)) {
            $sanitized[$name] = '[UNSUPPORTED_VALUE]';
            continue;
        }

        $stringValue = (string) $value;
        $sanitized[$name] = strlen($stringValue) > 2048
            ? substr($stringValue, 0, 2048) . '…[TRUNCATED]'
            : $stringValue;
    }

    ksort($sanitized);
    return $sanitized;
}

/**
 * リダイレクト要求時のPHP $_SERVERをJSON化する。
 * OSプロセス全体のgetenv()は接続パスワード等を含む可能性があるため、アクセス解析には収集しない。
 */
function nfc_request_metadata(): string
{
    $payload = [
        'schema_version' => 1,
        'captured_at_utc' => gmdate('c'),
        'request_server_variables' => nfc_sanitize_request_variables($_SERVER),
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);

    if (!is_string($json)) {
        return '{"schema_version":1,"capture_error":"json_encode_failed"}';
    }

    if (strlen($json) > 60000) {
        return '{"schema_version":1,"capture_error":"metadata_too_large","request_server_variables":"[TRUNCATED]"}';
    }

    return $json;
}

/**
 * URLへ到達した時点のリダイレクト要求数と解析イベントを記録する。
 * 計測書き込みに失敗しても、NFC利用者の転送を止めないため例外は呼び出し元で握りつぶせる設計にする。
 */
function nfc_record_redirect(PDO $pdo, int $redirectId): void
{
    $metadata = nfc_request_metadata();
    $clientIp = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 2048);
    $referrer = substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 2048);
    $method = substr((string) ($_SERVER['REQUEST_METHOD'] ?? ''), 0, 16);
    $requestUri = substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 2048);

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT INTO redirect_events
                (redirect_id, client_ip, user_agent, referrer, request_method, request_uri, request_meta_json)
             VALUES
                (:redirect_id, :client_ip, :user_agent, :referrer, :request_method, :request_uri, :request_meta_json)'
        );
        $insert->execute([
            ':redirect_id' => $redirectId,
            ':client_ip' => $clientIp,
            ':user_agent' => $userAgent,
            ':referrer' => $referrer,
            ':request_method' => $method,
            ':request_uri' => $requestUri,
            ':request_meta_json' => $metadata,
        ]);

        $update = $pdo->prepare(
            'UPDATE redirects
             SET access_count = access_count + 1, last_accessed_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $update->execute([':id' => $redirectId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
