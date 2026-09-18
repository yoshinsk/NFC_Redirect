# NFC Redirect

[NFC（Near Field Communication）](https://en.wikipedia.org/wiki/Near-field_communication)のタグや、NFCを内蔵したピンバッジをスマートフォンでかざしたときに、設定済みのURLへ転送するためのPHPアプリケーションです。

NFCタグには `https://nfc.example.com/[識別子]/` のような固定URLを書き込みます。管理画面から転送先を変更すれば、タグやピンバッジを書き換えずに遷移先を切り替えられます。

![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)

## 主な機能

- PHPセッションによる管理コンソールのサインイン
- NFC識別子と転送先URLの新規登録・編集・削除
- `random_bytes()`で生成する32文字の初期識別子と、手動指定した8〜64文字の識別子
- 302および`Cache-Control: no-store`による、転送先変更の即時反映
- リダイレクト要求数、最終アクセス日時、アクセス解析へのリンク
- PHPのリクエスト時`$_SERVER`、IPアドレス、User-Agent、Refererの記録
- 削除確認モーダルと外部キー`ON DELETE CASCADE`によるアクセス解析の同時削除
- CSRF対策、パスワードハッシュ照合、HTTPS専用のHttpOnly/SameSite Cookie、30分のアイドルタイムアウト、ログイン試行待機制御
- Bootstrap 5.3.3を利用したレスポンシブな管理画面とNoto Sans JP

## 画面構成

```text
サインイン
└── リダイレクト先リスト
    ├── 新規登録
    ├── アクセス解析
    ├── 編集
    └── 削除（確認ダイアログ）
```

一覧には、NFC URL、転送先、利用数（リダイレクト要求数）、最終アクセス、アクセス解析、編集、削除を表示します。

## ディレクトリ構成

```text
app/                       共通PHPロジック。公開しない。
private/config.php         公開用の汎用設定サンプル。公開しない。
private/config.local.php   本番固有設定。Git管理外。
private/credentials.php    DBパスワードと管理パスワードハッシュ。Git管理外。
public/                    Web公開ディレクトリへ配置するファイル。
schema/schema.sql          MariaDB初期スキーマ。
```

本番では、`app/` と `private/` を公開ディレクトリの親階層に置き、`public/` の内容だけをWeb公開ディレクトリへ配置します。`private/credentials.php` と `private/config.local.php` は `.gitignore` で除外済みです。

## 要件

- PHP 8.0以上（`pdo_mysql`、セッション、`random_bytes()`、`password_hash()`を利用）
- MariaDB 10.4以上またはMySQL 8.0以上
- Apacheの`mod_rewrite`（`public/.htaccess`を有効化）
- HTTPSで公開された独自ドメイン

## 初期化

1. `schema/schema.sql` をアプリ専用の空MariaDBデータベースへ一度だけ適用します。
2. `private/credentials.php.example` を `private/credentials.php` として複製します。
3. `private/config.local.php.example` を `private/config.local.php` として複製します。
4. `credentials.php` の `database_password` にDBパスワードを設定します。管理画面パスワードは、PHPの`password_hash()`で生成した結果だけを `admin_password_hash` に設定します。平文パスワードはPHPファイルへ保存しません。
5. `config.local.php` のデータベース名・ユーザー名・公開URL・管理ユーザー名を、設置先の値へ変更します。
6. `app/`、`private/`、`public/` をvhost配下へ配置し、`public/`だけをドキュメントルートに設定します。
7. `private/credentials.php` と `private/config.local.php` の権限をWebサーバー実行ユーザーだけが読める状態にします（例: `600`）。
8. `https://nfc.example.com/admin/` を開き、設定済みの管理アカウントでサインインします。

## 解析情報の扱い

アクセス解析は、リダイレクト要求の時刻、接続元IP、User-Agent、Referer、リクエストURI、およびPHPの`$_SERVER`で取得したリクエスト環境情報をDBに保存します。

ただし、`Authorization`、Cookie、パスワード、トークン、セッション、APIキー等を示す名前の値は`[REDACTED]`へ置換します。OSプロセス全体の`getenv()`はDB接続情報等を含み得るため保存しません。過大な値は1項目あたり2,048バイト、JSON全体は60,000バイトで打ち切ります。

「利用数」は**リダイレクト要求数**です。NFC読み取り時のOSプレビュー、クローラ、セキュリティ製品のアクセスも含み、遷移先ページの完全な表示や人間による閲覧を保証する値ではありません。解析DBへの書き込みに失敗しても、転送自体は継続します。

転送先を削除すると、このアプリDBの関連アクセス解析は同一トランザクションで削除されます。Webサーバーのアクセスログおよびバックアップに残る情報の保持・削除は、サーバーの別途運用方針に従います。

## セキュリティ上の前提

- 転送先は管理者が保存したHTTP/HTTPS URLのみであり、公開URLのクエリ値を`Location`へ渡しません。
- URL内の認証情報、改行、`javascript:`等は保存できません。
- URL識別子は`random_bytes()`から生成します。PHPの`uniqid()`を秘密トークンとして使いません。
- 複数管理者が必要になった時は、共有アカウントではなく個人アカウントと操作監査を追加してください。
- 解析にIPアドレス等を保存するため、利用目的・保存期間・閲覧権限を運用者が定め、公開ページ等で必要な表示を行ってください。

## UI方針と参照元

Bootstrapをレスポンシブ基盤として使い、Noto Sans JPを読み込みます。フォームの明確なラベル・サポートテキスト・具体的なエラー、十分なフォーカス表示、操作領域、モバイル表の横スクロールを実装しています。デジタル庁デザインシステムの考え方を参考にしていますが、公式な適合認定を示すものではありません。

- [デジタル庁デザインシステム](https://design.digital.go.jp/dads/)
- [アクセシビリティ](https://design.digital.go.jp/dads/guidance/accessibility/)
- [インプットテキスト](https://design.digital.go.jp/dads/components/input-text/)
- [ボタン](https://design.digital.go.jp/dads/components/button/)
- [テーブル／データテーブル](https://design.digital.go.jp/dads/components/table/)

## 確認項目

1. サインイン失敗・成功、30分後のセッション期限、サインアウトを確認する。
2. 新規登録後、NFC URLが302で指定先へ転送されることを確認する。
3. 利用数が増え、アクセス解析に記録されることを確認する。
4. 転送先編集直後に、同じNFC URLが新しい転送先へ302となることを確認する。
5. 削除確認モーダルから削除し、対象URLが404、アクセス解析も0件となることを確認する。

## ライセンス

[MIT License](LICENSE)です。
