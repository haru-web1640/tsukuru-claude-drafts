=== Tsukuru Claude Drafts ===
Contributors: yamaharu
Tags: mcp, claude, ai, drafts, rewrite
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Claude（MCP Adapter経由）から記事の下書きを作成・更新するためのアビリティを登録します。公開はできません。

== Description ==

MCP Adapter プラグインと組み合わせて使います。Claude から次の操作ができるようになります。

* 新しい下書きを作成
* 下書き・レビュー待ちの記事を更新
* 下書き一覧を取得
* カテゴリ一覧を取得
* 記事の本文をブロック形式で取得（1.1.0〜）
* 公開記事をもとにリライト用の下書きを作成（1.1.0〜）

公開・予約投稿・削除、公開済み記事の書き換えはできない設計です。
Claude 用には「寄稿者」権限の専用ユーザーを作り、そのユーザーのアプリケーションパスワードで接続してください。

== Installation ==

1. MCP Adapter プラグインをインストールして有効化します。
2. 「プラグイン → 新規追加 → プラグインのアップロード」から、このプラグインの zip をアップロードして有効化します。

アップデートするときは、新しい zip を同じ手順でアップロードし、「現在のものをアップロードしたもので置き換える」を選びます。

== Changelog ==

= 1.1.0 - 2026-10-29 =
* 追加：記事を読む（tsukuru/get-post）
* 追加：リライト用の下書きを作成（tsukuru/create-rewrite-draft）。元の記事は変更しません
* 変更：リライト用の下書きでは、結果に rewrite_of（リライト元の投稿ID）が含まれます
* 追加：readme.txt と CHANGELOG.md

= 1.0.0 - 2026-10-26 =
* 初版：下書きの作成・更新・一覧、カテゴリ一覧

== Upgrade Notice ==

= 1.1.0 =
公開記事を元の記事に触れずにリライトするための機能を追加しました。これまでの機能の使い方は変わりません。
