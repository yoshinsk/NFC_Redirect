<?php
/**
 * ファイル: public/admin/index.php
 * 機能: PHPセッション認証された管理者へ、NFC転送先の登録・編集・解析・削除機能を提供する。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
require dirname(__DIR__, 2) . '/app/admin.php';

nfc_start_admin_session();
$action = (string) ($_GET['action'] ?? 'list');

if ($action === 'login') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        nfc_verify_login_csrf();
        $username = (string) ($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        if (nfc_attempt_login($username, $password)) {
            nfc_flash('success', 'サインインしました。');
            header('Location: /admin/', true, 303);
            exit;
        }

        $message = nfc_login_is_rate_limited()
            ? '試行回数が上限に達しました。15分後に再度お試しください。'
            : 'ユーザー名またはパスワードを確認してください。';
        nfc_admin_login_page($message);
        exit;
    }

    if (nfc_is_admin_authenticated()) {
        header('Location: /admin/', true, 303);
        exit;
    }
    nfc_admin_login_page();
    exit;
}

nfc_require_admin();

if ($action === 'logout') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Method Not Allowed');
    }
    nfc_verify_csrf();
    nfc_logout();
    header('Location: /admin/?action=login', true, 303);
    exit;
}

try {
    $pdo = nfc_pdo();

    if ($action === 'create') {
        nfc_admin_render('新規登録', nfc_admin_redirect_form([
            'slug' => nfc_generate_slug(),
            'destination_url' => '',
        ], [], false));
        exit;
    }

    if ($action === 'store') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            exit('Method Not Allowed');
        }
        nfc_verify_csrf();
        $validated = nfc_validate_redirect_input($_POST);
        if ($validated['errors'] !== []) {
            nfc_admin_render('新規登録', nfc_admin_redirect_form($validated['data'], $validated['errors'], false));
            exit;
        }

        try {
            $statement = $pdo->prepare('INSERT INTO redirects (slug, destination_url) VALUES (:slug, :destination_url)');
            $statement->execute($validated['data']);
        } catch (PDOException $exception) {
            nfc_admin_render('新規登録', nfc_admin_redirect_form($validated['data'], [
                'slug' => '＊この識別子は既に登録されています。別の識別子にしてください。',
            ], false));
            exit;
        }

        nfc_flash('success', 'NFC URLを登録しました。');
        header('Location: /admin/', true, 303);
        exit;
    }

    if ($action === 'edit') {
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            http_response_code(404);
            exit('対象の転送先が見つかりません。');
        }
        $statement = $pdo->prepare('SELECT id, slug, destination_url FROM redirects WHERE id = :id');
        $statement->execute([':id' => $id]);
        $redirect = $statement->fetch();
        if (!is_array($redirect)) {
            http_response_code(404);
            exit('対象の転送先が見つかりません。');
        }
        nfc_admin_render('転送先を編集', nfc_admin_redirect_form([
            'slug' => (string) $redirect['slug'],
            'destination_url' => (string) $redirect['destination_url'],
        ], [], true, (int) $redirect['id']));
        exit;
    }

    if ($action === 'update') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            exit('Method Not Allowed');
        }
        nfc_verify_csrf();
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            http_response_code(404);
            exit('対象の転送先が見つかりません。');
        }
        $validated = nfc_validate_redirect_input($_POST);
        if ($validated['errors'] !== []) {
            nfc_admin_render('転送先を編集', nfc_admin_redirect_form($validated['data'], $validated['errors'], true, $id));
            exit;
        }

        try {
            $statement = $pdo->prepare('UPDATE redirects SET slug = :slug, destination_url = :destination_url WHERE id = :id');
            $statement->execute($validated['data'] + [':id' => $id]);
        } catch (PDOException $exception) {
            nfc_admin_render('転送先を編集', nfc_admin_redirect_form($validated['data'], [
                'slug' => '＊この識別子は既に登録されています。別の識別子にしてください。',
            ], true, $id));
            exit;
        }

        nfc_flash('success', '転送先を更新しました。');
        header('Location: /admin/', true, 303);
        exit;
    }

    if ($action === 'delete') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            exit('Method Not Allowed');
        }
        nfc_verify_csrf();
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $confirmationSlug = (string) ($_POST['confirmation_slug'] ?? '');
        if (!$id || !preg_match('/\A[a-z0-9][a-z0-9_-]{7,63}\z/', $confirmationSlug)) {
            http_response_code(400);
            exit('削除対象を確認できません。');
        }

        $pdo->beginTransaction();
        try {
            $lookup = $pdo->prepare('SELECT id, slug FROM redirects WHERE id = :id FOR UPDATE');
            $lookup->execute([':id' => $id]);
            $redirect = $lookup->fetch();
            if (!is_array($redirect) || !hash_equals((string) $redirect['slug'], $confirmationSlug)) {
                throw new RuntimeException('削除対象を確認できません。');
            }
            $count = $pdo->prepare('SELECT COUNT(*) FROM redirect_events WHERE redirect_id = :id');
            $count->execute([':id' => $id]);
            $eventCount = (int) $count->fetchColumn();
            $delete = $pdo->prepare('DELETE FROM redirects WHERE id = :id');
            $delete->execute([':id' => $id]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            nfc_flash('error', '削除できませんでした。対象が既に変更または削除されている可能性があります。');
            header('Location: /admin/', true, 303);
            exit;
        }

        nfc_flash('success', '転送先とアクセス解析 ' . number_format($eventCount) . '件を削除しました。');
        header('Location: /admin/', true, 303);
        exit;
    }

    if ($action === 'analytics') {
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            http_response_code(404);
            exit('対象の転送先が見つかりません。');
        }
        $lookup = $pdo->prepare('SELECT id, slug FROM redirects WHERE id = :id');
        $lookup->execute([':id' => $id]);
        $redirect = $lookup->fetch();
        if (!is_array($redirect)) {
            http_response_code(404);
            exit('対象の転送先が見つかりません。');
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $offset = ($page - 1) * 50;
        $count = $pdo->prepare('SELECT COUNT(*) FROM redirect_events WHERE redirect_id = :id');
        $count->execute([':id' => $id]);
        $total = (int) $count->fetchColumn();
        $events = $pdo->prepare(
            'SELECT accessed_at, client_ip, user_agent, referrer, request_meta_json
             FROM redirect_events WHERE redirect_id = :id
             ORDER BY accessed_at DESC, id DESC LIMIT 50 OFFSET :offset'
        );
        $events->bindValue(':id', $id, PDO::PARAM_INT);
        $events->bindValue(':offset', $offset, PDO::PARAM_INT);
        $events->execute();
        nfc_admin_analytics_page($redirect, $events->fetchAll(), $page, $total);
        exit;
    }

    $search = trim((string) ($_GET['q'] ?? ''));
    $search = substr($search, 0, 128);
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $offset = ($page - 1) * 25;
    $where = '';
    $parameters = [];
    if ($search !== '') {
        $where = ' WHERE slug LIKE :search OR destination_url LIKE :search';
        $parameters[':search'] = '%' . $search . '%';
    }
    $count = $pdo->prepare('SELECT COUNT(*) FROM redirects' . $where);
    $count->execute($parameters);
    $total = (int) $count->fetchColumn();
    $list = $pdo->prepare(
        'SELECT id, slug, destination_url, access_count, last_accessed_at FROM redirects'
        . $where
        . ' ORDER BY created_at DESC, id DESC LIMIT 25 OFFSET :offset'
    );
    foreach ($parameters as $name => $value) {
        $list->bindValue($name, $value, PDO::PARAM_STR);
    }
    $list->bindValue(':offset', $offset, PDO::PARAM_INT);
    $list->execute();
    nfc_admin_list_page($list->fetchAll(), $search, $page, $total);
} catch (Throwable $exception) {
    error_log('nfc_admin_application_error');
    http_response_code(503);
    nfc_admin_render('一時的なエラー', '<h1>一時的に管理画面を表示できません</h1><p>時間をおいて再度お試しください。</p>');
}
