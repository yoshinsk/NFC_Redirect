<?php
/**
 * ファイル: app/admin.php
 * 機能: セッション認証済みの管理コンソール用に、一覧・登録・編集・解析・削除画面を描画する。
 */

declare(strict_types=1);

/**
 * 管理画面共通のHTML枠を出力する。
 * BootstrapとNoto Sans JPを読み込み、各画面のDOM順序を操作順序と一致させる。
 */
function nfc_admin_render(string $title, string $content, bool $showNavigation = true): void
{
    $flash = nfc_pull_flash();
    $flashHtml = '';
    if ($flash !== null) {
        $class = $flash['type'] === 'success' ? 'alert-success' : 'alert-danger';
        $flashHtml = '<div class="alert ' . $class . ' alert-dismissible fade show" role="status">'
            . nfc_h($flash['message'])
            . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="閉じる"></button></div>';
    }

    $navigation = '';
    if ($showNavigation) {
        $csrfToken = nfc_h((string) ($_SESSION['csrf_token'] ?? ''));
        $navigation = <<<HTML
<header class="app-header border-bottom">
  <div class="container py-3 d-flex flex-wrap justify-content-between align-items-center gap-3">
    <a class="app-brand" href="/admin/">NFC Redirect</a>
    <nav class="d-flex align-items-center gap-2" aria-label="管理メニュー">
      <a class="btn btn-outline-primary" href="/admin/">転送先リスト</a>
      <a class="btn btn-primary" href="/admin/?action=create">新規登録</a>
      <form method="post" action="/admin/?action=logout" class="d-inline">
        <input type="hidden" name="csrf_token" value="{$csrfToken}">
        <button class="btn btn-link text-decoration-none" type="submit">サインアウト</button>
      </form>
    </nav>
  </div>
</header>
HTML;
    }

    $safeTitle = nfc_h($title);
    echo <<<HTML
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title>{$safeTitle} | NFC Redirect</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/admin/assets/app.css" rel="stylesheet">
</head>
<body>
  {$navigation}
  <main class="container py-4 py-md-5" id="main-content">
    {$flashHtml}
    {$content}
  </main>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="/admin/assets/app.js"></script>
</body>
</html>
HTML;
}

/**
 * 新規・編集で共用するフォームを描画する。
 * 値の補助説明をラベルと入力欄の間に置き、プレースホルダーへ依存しない。
 *
 * @param array{slug: string, destination_url: string} $data
 * @param array<string, string> $errors
 */
function nfc_admin_redirect_form(array $data, array $errors, bool $isEdit, ?int $id = null): string
{
    $action = $isEdit ? '/admin/?action=update&id=' . (int) $id : '/admin/?action=store';
    $heading = $isEdit ? '転送先を編集' : '転送先を新規登録';
    $submit = $isEdit ? '変更を保存' : 'NFC URLを登録';
    $csrfToken = nfc_h((string) ($_SESSION['csrf_token'] ?? ''));
    $slugError = isset($errors['slug']) ? '<p class="form-error" id="slug-error">' . nfc_h($errors['slug']) . '</p>' : '';
    $urlError = isset($errors['destination_url']) ? '<p class="form-error" id="destination-error">' . nfc_h($errors['destination_url']) . '</p>' : '';
    $slugInvalid = isset($errors['slug']) ? ' is-invalid' : '';
    $urlInvalid = isset($errors['destination_url']) ? ' is-invalid' : '';
    $slugDescribedBy = isset($errors['slug']) ? 'slug-help slug-error' : 'slug-help';
    $urlDescribedBy = isset($errors['destination_url']) ? 'destination-help destination-error' : 'destination-help';
    $slug = nfc_h($data['slug']);
    $publicUrl = nfc_h(nfc_public_url($data['slug']));
    $destinationUrl = nfc_h($data['destination_url']);

    return <<<HTML
<div class="content-heading">
  <div>
    <p class="eyebrow">NFC URL 管理</p>
    <h1>{$heading}</h1>
    <p class="text-secondary mb-0">登録後のURLをNFCタグやNFCピンバッジへ書き込みます。転送先は後から変更できます。</p>
  </div>
  <a class="btn btn-outline-primary" href="/admin/">リストへ戻る</a>
</div>
<section class="card form-card mt-4" aria-labelledby="form-title">
  <div class="card-body p-4 p-md-5">
    <h2 class="h4" id="form-title">転送設定</h2>
    <form method="post" action="{$action}" novalidate>
      <input type="hidden" name="csrf_token" value="{$csrfToken}">
      <div class="mb-4">
        <label class="form-label fw-bold" for="slug">NFC識別子 <span class="required">※必須</span></label>
        <p class="form-text" id="slug-help">英小文字・数字・ハイフン・アンダースコアで8〜64文字。推測されにくいランダム値を初期設定しています。</p>
        <input class="form-control form-control-lg{$slugInvalid}" id="slug" name="slug" value="{$slug}" aria-describedby="{$slugDescribedBy}" autocomplete="off" required>
        {$slugError}
        <p class="mt-2 mb-0"><span class="text-secondary">発行URL：</span><code class="nfc-url">{$publicUrl}</code></p>
      </div>
      <div class="mb-4">
        <label class="form-label fw-bold" for="destination_url">転送先URL <span class="required">※必須</span></label>
        <p class="form-text" id="destination-help">http または https で始まる完全なURLを入力します。URL内のID・パスワードは登録できません。</p>
        <input class="form-control form-control-lg{$urlInvalid}" id="destination_url" name="destination_url" value="{$destinationUrl}" inputmode="url" aria-describedby="{$urlDescribedBy}" autocomplete="url" required>
        {$urlError}
      </div>
      <div class="d-flex flex-column flex-sm-row gap-2">
        <button class="btn btn-primary" type="submit">{$submit}</button>
        <a class="btn btn-outline-secondary" href="/admin/">登録せず戻る</a>
      </div>
    </form>
  </div>
</section>
HTML;
}

/**
 * 管理者用のサインイン画面を描画する。
 */
function nfc_admin_login_page(?string $error = null): void
{
    $errorHtml = $error === null ? '' : '<div class="alert alert-danger" role="alert">' . nfc_h($error) . '</div>';
    $loginCsrfToken = nfc_h((string) ($_SESSION['login_csrf_token'] ?? ''));
    $content = <<<HTML
<section class="login-shell" aria-labelledby="login-title">
  <p class="eyebrow">NFC Redirect</p>
  <h1 id="login-title">サインイン</h1>
  <p class="text-secondary">管理コンソールへサインインしてください。</p>
  {$errorHtml}
  <form method="post" action="/admin/?action=login" class="mt-4" novalidate>
    <input type="hidden" name="login_csrf_token" value="{$loginCsrfToken}">
    <div class="mb-3">
      <label class="form-label fw-bold" for="username">ユーザー名 <span class="required">※必須</span></label>
      <input class="form-control form-control-lg" id="username" name="username" autocomplete="username" required autofocus>
    </div>
    <div class="mb-4">
      <label class="form-label fw-bold" for="password">パスワード <span class="required">※必須</span></label>
      <input class="form-control form-control-lg" id="password" name="password" type="password" autocomplete="current-password" required>
    </div>
    <button class="btn btn-primary w-100" type="submit">サインイン</button>
  </form>
</section>
HTML;
    nfc_admin_render('サインイン', $content, false);
}

/**
 * 転送先一覧と検索フォームを描画する。
 *
 * @param list<array<string, mixed>> $redirects
 */
function nfc_admin_list_page(array $redirects, string $search, int $page, int $total): void
{
    $csrfToken = nfc_h((string) ($_SESSION['csrf_token'] ?? ''));
    $rows = '';
    foreach ($redirects as $redirect) {
        $id = (int) $redirect['id'];
        $slug = (string) $redirect['slug'];
        $publicUrl = nfc_public_url($slug);
        $destination = (string) $redirect['destination_url'];
        $count = number_format((int) $redirect['access_count']);
        $lastAccess = $redirect['last_accessed_at'] ? nfc_h((string) $redirect['last_accessed_at']) : '未アクセス';
        $rows .= '<tr>'
            . '<td><code class="nfc-url">' . nfc_h($publicUrl) . '</code><button type="button" class="btn btn-sm btn-outline-secondary ms-2 copy-url" data-copy-text="' . nfc_h($publicUrl) . '">コピー</button></td>'
            . '<td class="destination-cell"><a href="' . nfc_h($destination) . '" target="_blank" rel="noopener noreferrer">' . nfc_h($destination) . '</a></td>'
            . '<td><strong>' . $count . '</strong><span class="d-block small text-secondary">リダイレクト要求数</span></td>'
            . '<td>' . $lastAccess . '</td>'
            . '<td><a class="btn btn-sm btn-outline-primary" href="/admin/?action=analytics&id=' . $id . '">アクセス解析</a></td>'
            . '<td><a class="btn btn-sm btn-outline-primary" href="/admin/?action=edit&id=' . $id . '">編集</a></td>'
            . '<td><button type="button" class="btn btn-sm btn-outline-danger delete-trigger" data-bs-toggle="modal" data-bs-target="#deleteModal" data-id="' . $id . '" data-slug="' . nfc_h($slug) . '" data-count="' . $count . '">削除</button></td>'
            . '</tr>';
    }

    if ($rows === '') {
        $rows = '<tr><td colspan="7" class="text-center py-5 text-secondary">該当する転送先はありません。</td></tr>';
    }

    $searchValue = nfc_h($search);
    $from = $total === 0 ? 0 : (($page - 1) * 25 + 1);
    $to = min($page * 25, $total);
    $previous = $page > 1 ? '<a class="btn btn-outline-secondary" href="/admin/?page=' . ($page - 1) . '&q=' . rawurlencode($search) . '">前の25件</a>' : '';
    $next = $to < $total ? '<a class="btn btn-outline-secondary" href="/admin/?page=' . ($page + 1) . '&q=' . rawurlencode($search) . '">次の25件</a>' : '';

    $content = <<<HTML
<div class="content-heading">
  <div>
    <p class="eyebrow">NFC URL 管理</p>
    <h1>リダイレクト先リスト</h1>
    <p class="text-secondary mb-0">NFCタグやピンバッジへ書き込むURLと転送先を管理します。</p>
  </div>
  <a class="btn btn-primary" href="/admin/?action=create">新規登録</a>
</div>
<section class="card mt-4" aria-labelledby="list-title">
  <div class="card-body p-3 p-md-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-3">
      <div>
        <h2 class="h4 mb-1" id="list-title">登録済み転送先</h2>
        <p class="mb-0 text-secondary">{$total}件中 {$from}〜{$to}件を表示</p>
      </div>
      <form class="search-form" method="get" action="/admin/">
        <label class="visually-hidden" for="search">識別子または転送先URLで検索</label>
        <input class="form-control" id="search" name="q" value="{$searchValue}" placeholder="識別子または転送先URLで検索">
        <button class="btn btn-outline-primary" type="submit">検索</button>
      </form>
    </div>
    <div class="table-responsive table-scroll-shadow">
      <table class="table table-hover align-middle mb-0">
        <thead><tr><th scope="col">NFC URL</th><th scope="col">転送先</th><th scope="col">利用数</th><th scope="col">最終アクセス</th><th scope="col"><span class="visually-hidden">アクセス解析</span></th><th scope="col"><span class="visually-hidden">編集</span></th><th scope="col"><span class="visually-hidden">削除</span></th></tr></thead>
        <tbody>{$rows}</tbody>
      </table>
    </div>
    <div class="d-flex justify-content-between align-items-center gap-2 mt-3">{$previous}<span class="small text-secondary">{$page}ページ</span>{$next}</div>
  </div>
</section>
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalTitle" aria-describedby="deleteModalDescription" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title fs-5" id="deleteModalTitle">転送先を削除しますか</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button></div>
    <div class="modal-body"><p id="deleteModalDescription">この操作は元に戻せません。</p><dl class="mb-0"><dt>NFC識別子</dt><dd id="deleteSlug"></dd><dt>削除するアクセス解析</dt><dd><span id="deleteCount"></span>件</dd></dl></div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">キャンセル</button><form method="post" action="/admin/?action=delete" class="d-inline"><input type="hidden" name="csrf_token" value="{$csrfToken}"><input type="hidden" name="id" id="deleteId"><input type="hidden" name="confirmation_slug" id="deleteConfirmationSlug"><button class="btn btn-danger" type="submit">アクセス解析を含めて削除</button></form></div>
  </div></div>
</div>
HTML;
    nfc_admin_render('リダイレクト先リスト', $content);
}

/**
 * 特定URLのアクセス解析一覧を、個人情報を含む可能性を明記して描画する。
 *
 * @param list<array<string, mixed>> $events
 */
function nfc_admin_analytics_page(array $redirect, array $events, int $page, int $total): void
{
    $slug = (string) $redirect['slug'];
    $eventRows = '';
    foreach ($events as $event) {
        $metadata = (string) $event['request_meta_json'];
        $prettyMetadata = json_encode(json_decode($metadata, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($prettyMetadata)) {
            $prettyMetadata = $metadata;
        }
        $eventRows .= '<tr>'
            . '<td>' . nfc_h($event['accessed_at']) . '</td>'
            . '<td><code>' . nfc_h($event['client_ip']) . '</code></td>'
            . '<td class="user-agent-cell">' . nfc_h($event['user_agent']) . '</td>'
            . '<td class="destination-cell">' . nfc_h($event['referrer'] ?: '—') . '</td>'
            . '<td><details><summary>表示</summary><pre class="metadata">' . nfc_h($prettyMetadata) . '</pre></details></td>'
            . '</tr>';
    }
    if ($eventRows === '') {
        $eventRows = '<tr><td colspan="5" class="text-center py-5 text-secondary">アクセス記録はまだありません。</td></tr>';
    }

    $from = $total === 0 ? 0 : (($page - 1) * 50 + 1);
    $to = min($page * 50, $total);
    $previous = $page > 1 ? '<a class="btn btn-outline-secondary" href="/admin/?action=analytics&id=' . (int) $redirect['id'] . '&page=' . ($page - 1) . '">前の50件</a>' : '';
    $next = $to < $total ? '<a class="btn btn-outline-secondary" href="/admin/?action=analytics&id=' . (int) $redirect['id'] . '&page=' . ($page + 1) . '">次の50件</a>' : '';
    $content = '<div class="content-heading"><div><p class="eyebrow">アクセス解析</p><h1>アクセス記録</h1><p class="text-secondary mb-0"><code class="nfc-url">' . nfc_h(nfc_public_url($slug)) . '</code> の記録です。</p></div><a class="btn btn-outline-primary" href="/admin/">リストへ戻る</a></div>'
        . '<div class="alert alert-warning mt-4" role="note"><strong>取扱注意：</strong>IPアドレス、User-Agent、Referer、およびマスキング済みのリクエスト環境情報を表示しています。転送先を削除すると、このDB内のアクセス解析も削除されます。</div>'
        . '<section class="card mt-4" aria-labelledby="analytics-title"><div class="card-body p-3 p-md-4"><div class="d-flex justify-content-between align-items-end mb-3"><div><h2 class="h4 mb-1" id="analytics-title">アクセスのあったユーザー</h2><p class="mb-0 text-secondary">' . $total . '件中 ' . $from . '〜' . $to . '件を表示</p></div></div><div class="table-responsive table-scroll-shadow"><table class="table table-hover align-middle mb-0"><thead><tr><th scope="col">日時</th><th scope="col">接続元IP</th><th scope="col">User-Agent</th><th scope="col">Referer</th><th scope="col">環境情報</th></tr></thead><tbody>' . $eventRows . '</tbody></table></div><div class="d-flex justify-content-between align-items-center gap-2 mt-3">' . $previous . '<span class="small text-secondary">' . $page . 'ページ</span>' . $next . '</div></div></section>';
    nfc_admin_render('アクセス解析', $content);
}
