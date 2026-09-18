<?php
/**
 * ファイル: private/config.php
 * 機能: 公開ディレクトリ外で、接続先とセッション認証の非機密設定を定義する。
 */

declare(strict_types=1);

$credentialsPath = __DIR__ . '/credentials.php';
if (!is_file($credentialsPath)) {
    throw new RuntimeException('本番用の認証情報ファイルが見つかりません。');
}

$credentials = require $credentialsPath;
if (!is_array($credentials)) {
    throw new RuntimeException('本番用の認証情報ファイルの形式が不正です。');
}

return [
    'database' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'example_database',
        'user' => 'example_user',
        'password' => (string) ($credentials['database_password'] ?? ''),
    ],
    'app' => [
        'base_url' => 'https://nfc.example.com',
        'admin_session_timeout' => 1800,
    ],
    'admin' => [
        'username' => 'admin',
        'password_hash' => (string) ($credentials['admin_password_hash'] ?? ''),
    ],
];
