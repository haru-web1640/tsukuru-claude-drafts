<?php
/**
 * Plugin Name:       Tsukuru Claude Drafts
 * Description:       Claude（MCP Adapter経由）から記事の下書きを作成・更新するためのアビリティを登録します。公開はできません。
 * Version:           1.1.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            やまはる
 * License:           GPL-2.0-or-later
 * Text Domain:       tsukuru-claude-drafts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TSUKURU_CLAUDE_DRAFTS_VERSION', '1.1.0' );

/** リライト元の投稿IDを記録するメタキー（_ 始まりなのでカスタムフィールド欄には表示されない） */
define( 'TSUKURU_CLAUDE_DRAFTS_REWRITE_META', '_tsukuru_rewrite_of' );

/*
 * このプラグインでできること（公開記事は読むだけ。書き込みはすべて「下書き」止まり）
 * - tsukuru/create-draft         … 新しい下書きを作成
 * - tsukuru/update-draft         … 下書き・レビュー待ちの記事を更新
 * - tsukuru/list-drafts          … 自分の下書き一覧を取得
 * - tsukuru/list-categories      … カテゴリ一覧を取得
 * - tsukuru/get-post             … 記事の本文をブロック形式で取得（v1.1.0〜）
 * - tsukuru/create-rewrite-draft … 公開記事をもとにリライト用の下書きを作成（v1.1.0〜）
 *
 * 公開・予約投稿・削除、公開済み記事の書き換えはできない設計です。
 * 必要な権限は edit_posts（寄稿者以上）。Claude用ユーザーは「寄稿者」推奨。
 * 変更履歴は CHANGELOG.md を参照してください。
 */

/**
 * アビリティのカテゴリを登録
 */
add_action(
	'wp_abilities_api_categories_init',
	function () {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			'tsukuru-drafts',
			array(
				'label'       => '下書き作成（Claude用）',
				'description' => 'Claudeから記事の下書きを作成・更新するための機能です。',
			)
		);
	}
);

/**
 * アビリティを登録
 */
add_action(
	'wp_abilities_api_init',
	function () {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$can_edit = function () {
			return current_user_can( 'edit_posts' );
		};

		$meta = array(
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true ),
		);

		$post_output_schema = array(
			'type'       => 'object',
			'properties' => array(
				'id'         => array( 'type' => 'integer' ),
				'title'      => array( 'type' => 'string' ),
				'status'     => array( 'type' => 'string' ),
				'edit_url'   => array( 'type' => 'string' ),
				'rewrite_of' => array(
					'type'        => 'integer',
					'description' => 'リライト用の下書きの場合、リライト元の投稿ID（v1.1.0〜）',
				),
			),
		);

		// 1. 下書きを作成
		wp_register_ability(
			'tsukuru/create-draft',
			array(
				'label'               => '下書きを作成',
				'description'         => '新しい投稿を下書きとして保存します。公開はできません。content はブロックエディタ形式（<!-- wp:paragraph --><p>...</p><!-- /wp:paragraph --> のようなブロックコメント付きHTML）で渡してください。categories にはカテゴリ名またはIDを、tags にはタグ名を指定できます。',
				'category'            => 'tsukuru-drafts',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'title', 'content' ),
					'properties' => array(
						'title'      => array(
							'type'        => 'string',
							'description' => '記事タイトル',
						),
						'content'    => array(
							'type'        => 'string',
							'description' => '本文（ブロックエディタ形式のHTML）',
						),
						'excerpt'    => array(
							'type'        => 'string',
							'description' => '抜粋（任意）',
						),
						'slug'       => array(
							'type'        => 'string',
							'description' => 'スラッグ（任意・半角英数字とハイフン）',
						),
						'categories' => array(
							'type'        => 'array',
							'items'       => array( 'type' => array( 'string', 'integer' ) ),
							'description' => 'カテゴリ名またはカテゴリID（既存カテゴリのみ）',
						),
						'tags'       => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'タグ名',
						),
					),
				),
				'output_schema'       => $post_output_schema,
				'permission_callback' => $can_edit,
				'execute_callback'    => 'tsukuru_claude_drafts_create',
				'meta'                => $meta,
			)
		);

		// 2. 下書きを更新
		wp_register_ability(
			'tsukuru/update-draft',
			array(
				'label'               => '下書きを更新',
				'description'         => '下書きまたはレビュー待ちの投稿を更新します。公開済みの記事は変更できません。指定した項目だけが上書きされます。',
				'category'            => 'tsukuru-drafts',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'id' ),
					'properties' => array(
						'id'         => array(
							'type'        => 'integer',
							'description' => '更新する投稿のID',
						),
						'title'      => array( 'type' => 'string' ),
						'content'    => array( 'type' => 'string' ),
						'excerpt'    => array( 'type' => 'string' ),
						'slug'       => array( 'type' => 'string' ),
						'categories' => array(
							'type'  => 'array',
							'items' => array( 'type' => array( 'string', 'integer' ) ),
						),
						'tags'       => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
				'output_schema'       => $post_output_schema,
				'permission_callback' => $can_edit,
				'execute_callback'    => 'tsukuru_claude_drafts_update',
				'meta'                => $meta,
			)
		);

		// 3. 下書き一覧
		wp_register_ability(
			'tsukuru/list-drafts',
			array(
				'label'               => '下書き一覧を取得',
				'description'         => 'このユーザーが編集できる下書き・レビュー待ちの投稿を新しい順に返します。',
				'category'            => 'tsukuru-drafts',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'limit' => array(
							'type'        => 'integer',
							'description' => '取得件数（初期値20、最大50）',
						),
					),
				),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => $post_output_schema,
				),
				'permission_callback' => $can_edit,
				'execute_callback'    => 'tsukuru_claude_drafts_list',
				'meta'                => array_merge(
					$meta,
					array( 'annotations' => array( 'readonly' => true ) )
				),
			)
		);

		// 4. カテゴリ一覧
		wp_register_ability(
			'tsukuru/list-categories',
			array(
				'label'               => 'カテゴリ一覧を取得',
				'description'         => 'サイトのカテゴリ（ID・名前・スラッグ）を返します。下書き作成時のカテゴリ指定に使います。',
				'category'            => 'tsukuru-drafts',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => (object) array(),
				),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'   => array( 'type' => 'integer' ),
							'name' => array( 'type' => 'string' ),
							'slug' => array( 'type' => 'string' ),
						),
					),
				),
				'permission_callback' => $can_edit,
				'execute_callback'    => 'tsukuru_claude_drafts_categories',
				'meta'                => array_merge(
					$meta,
					array( 'annotations' => array( 'readonly' => true ) )
				),
			)
		);

		// 5. 記事を読む（v1.1.0〜）
		wp_register_ability(
			'tsukuru/get-post',
			array(
				'label'               => '記事を読む',
				'description'         => '投稿の本文をブロックエディタ形式（ブロックコメント付きHTML）のまま返します。公開記事のほか、このユーザーが編集できる下書きも読めます。id か slug のどちらかを指定してください。リライトの前に現在の内容を確認するときに使います。',
				'category'            => 'tsukuru-drafts',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id'   => array(
							'type'        => 'integer',
							'description' => '投稿ID',
						),
						'slug' => array(
							'type'        => 'string',
							'description' => 'スラッグ（URLの末尾部分）',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'         => array( 'type' => 'integer' ),
						'title'      => array( 'type' => 'string' ),
						'status'     => array( 'type' => 'string' ),
						'slug'       => array( 'type' => 'string' ),
						'link'       => array( 'type' => 'string' ),
						'date'       => array( 'type' => 'string' ),
						'modified'   => array( 'type' => 'string' ),
						'excerpt'    => array( 'type' => 'string' ),
						'categories' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'tags'       => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'content'    => array( 'type' => 'string' ),
						'rewrite_of' => array( 'type' => 'integer' ),
					),
				),
				'permission_callback' => $can_edit,
				'execute_callback'    => 'tsukuru_claude_drafts_get_post',
				'meta'                => array_merge(
					$meta,
					array( 'annotations' => array( 'readonly' => true ) )
				),
			)
		);

		// 6. リライト用の下書きを作成（v1.1.0〜）
		wp_register_ability(
			'tsukuru/create-rewrite-draft',
			array(
				'label'               => 'リライト用の下書きを作成',
				'description'         => '公開中の記事をもとに、リライト用の新しい下書きを作成します。元の記事は一切変更しません。content を省略すると元の本文をそのままコピーし、指定するとその内容で作成します（ブロックエディタ形式）。カテゴリとタグは元の記事から引き継ぎます。作成後は tsukuru/update-draft で続けて編集できます。',
				'category'            => 'tsukuru-drafts',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'source_id' ),
					'properties' => array(
						'source_id' => array(
							'type'        => 'integer',
							'description' => 'リライト元の公開記事のID',
						),
						'title'     => array(
							'type'        => 'string',
							'description' => '下書きのタイトル（省略時は「【リライト】元のタイトル」）',
						),
						'content'   => array(
							'type'        => 'string',
							'description' => 'リライト後の本文（省略時は元の本文をコピー）',
						),
						'excerpt'   => array(
							'type'        => 'string',
							'description' => '抜粋（省略時は元の抜粋をコピー）',
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'              => array( 'type' => 'integer' ),
						'title'           => array( 'type' => 'string' ),
						'status'          => array( 'type' => 'string' ),
						'edit_url'        => array( 'type' => 'string' ),
						'rewrite_of'      => array( 'type' => 'integer' ),
						'source_link'     => array( 'type' => 'string' ),
						'source_edit_url' => array( 'type' => 'string' ),
					),
				),
				'permission_callback' => $can_edit,
				'execute_callback'    => 'tsukuru_claude_drafts_create_rewrite',
				'meta'                => $meta,
			)
		);
	}
);

/**
 * カテゴリ名・IDの配列を既存カテゴリのIDに変換（存在しないものは無視）
 *
 * @param array $items カテゴリ名またはID。
 * @return int[]
 */
function tsukuru_claude_drafts_resolve_categories( $items ) {
	$ids = array();
	foreach ( (array) $items as $item ) {
		if ( is_numeric( $item ) ) {
			$term = get_term( (int) $item, 'category' );
		} else {
			$term = get_term_by( 'name', (string) $item, 'category' );
			if ( ! $term ) {
				$term = get_term_by( 'slug', sanitize_title( $item ), 'category' );
			}
		}
		if ( $term && ! is_wp_error( $term ) ) {
			$ids[] = (int) $term->term_id;
		}
	}
	return array_values( array_unique( $ids ) );
}

/**
 * 入力値から wp_insert_post 用の配列を組み立てる
 *
 * @param array $input 入力値。
 * @return array
 */
function tsukuru_claude_drafts_build_postarr( $input ) {
	$postarr = array();
	if ( isset( $input['title'] ) ) {
		$postarr['post_title'] = sanitize_text_field( $input['title'] );
	}
	if ( isset( $input['content'] ) ) {
		$postarr['post_content'] = (string) $input['content'];
	}
	if ( isset( $input['excerpt'] ) ) {
		$postarr['post_excerpt'] = sanitize_textarea_field( $input['excerpt'] );
	}
	if ( isset( $input['slug'] ) ) {
		$postarr['post_name'] = sanitize_title( $input['slug'] );
	}
	if ( isset( $input['categories'] ) ) {
		$postarr['post_category'] = tsukuru_claude_drafts_resolve_categories( $input['categories'] );
	}
	if ( isset( $input['tags'] ) ) {
		$postarr['tags_input'] = array_map( 'sanitize_text_field', (array) $input['tags'] );
	}
	return $postarr;
}

/**
 * 返り値の整形
 *
 * @param int $post_id 投稿ID。
 * @return array
 */
function tsukuru_claude_drafts_format( $post_id ) {
	$post = get_post( $post_id );
	$data = array(
		'id'       => (int) $post->ID,
		'title'    => $post->post_title,
		'status'   => $post->post_status,
		'edit_url' => admin_url( 'post.php?post=' . $post->ID . '&action=edit' ),
	);

	// v1.1.0〜：リライト用の下書きなら、リライト元のIDも返す
	$rewrite_of = (int) get_post_meta( $post->ID, TSUKURU_CLAUDE_DRAFTS_REWRITE_META, true );
	if ( $rewrite_of ) {
		$data['rewrite_of'] = $rewrite_of;
	}
	return $data;
}

/**
 * このユーザーが本文を読んでよい投稿か（v1.1.0〜）
 *
 * 公開中でパスワード保護のない記事は誰でも読めるので許可。
 * それ以外（下書き・非公開・パスワード保護）は編集権限がある場合のみ許可。
 *
 * @param WP_Post $post 投稿。
 * @return bool
 */
function tsukuru_claude_drafts_can_read( $post ) {
	if ( 'publish' === $post->post_status && '' === $post->post_password ) {
		return true;
	}
	return current_user_can( 'edit_post', $post->ID );
}

/**
 * 記事を読む（v1.1.0〜）
 *
 * @param array $input 入力値。
 * @return array|WP_Error
 */
function tsukuru_claude_drafts_get_post( $input = array() ) {
	$input = (array) $input;
	$post  = null;

	if ( ! empty( $input['id'] ) ) {
		$post = get_post( (int) $input['id'] );
	} elseif ( ! empty( $input['slug'] ) ) {
		$found = get_posts(
			array(
				'name'           => sanitize_title( $input['slug'] ),
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 1,
			)
		);
		$post  = $found ? $found[0] : null;
	} else {
		return new WP_Error( 'missing_param', 'id か slug のどちらかを指定してください。' );
	}

	if ( ! $post || 'post' !== $post->post_type ) {
		return new WP_Error( 'not_found', '指定された投稿が見つかりません。' );
	}
	if ( ! tsukuru_claude_drafts_can_read( $post ) ) {
		return new WP_Error( 'forbidden', 'この投稿を読む権限がありません。' );
	}

	$data = array(
		'id'         => (int) $post->ID,
		'title'      => $post->post_title,
		'status'     => $post->post_status,
		'slug'       => $post->post_name,
		'link'       => get_permalink( $post ),
		'date'       => get_post_time( 'c', false, $post ),
		'modified'   => get_post_modified_time( 'c', false, $post ),
		'excerpt'    => $post->post_excerpt,
		'categories' => wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) ),
		'tags'       => wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) ),
		'content'    => $post->post_content,
	);

	$rewrite_of = (int) get_post_meta( $post->ID, TSUKURU_CLAUDE_DRAFTS_REWRITE_META, true );
	if ( $rewrite_of ) {
		$data['rewrite_of'] = $rewrite_of;
	}
	return $data;
}

/**
 * リライト用の下書きを作成（v1.1.0〜）
 *
 * 元の記事は変更せず、新しい下書きとして保存する。
 *
 * @param array $input 入力値。
 * @return array|WP_Error
 */
function tsukuru_claude_drafts_create_rewrite( $input = array() ) {
	$input     = (array) $input;
	$source_id = isset( $input['source_id'] ) ? (int) $input['source_id'] : 0;
	$source    = get_post( $source_id );

	if ( ! $source || 'post' !== $source->post_type ) {
		return new WP_Error( 'not_found', 'リライト元の投稿が見つかりません。' );
	}
	if ( 'publish' !== $source->post_status ) {
		return new WP_Error( 'not_published', 'リライト元には公開中の記事を指定してください。下書きは tsukuru/update-draft で直接編集できます。' );
	}
	if ( ! tsukuru_claude_drafts_can_read( $source ) ) {
		return new WP_Error( 'forbidden', 'この投稿を読む権限がありません。' );
	}

	$postarr = array(
		'post_type'     => 'post',
		'post_status'   => 'draft', // 常に下書き固定
		'post_author'   => get_current_user_id(),
		'post_title'    => isset( $input['title'] )
			? sanitize_text_field( $input['title'] )
			: '【リライト】' . $source->post_title,
		'post_content'  => isset( $input['content'] ) ? (string) $input['content'] : $source->post_content,
		'post_excerpt'  => isset( $input['excerpt'] )
			? sanitize_textarea_field( $input['excerpt'] )
			: $source->post_excerpt,
		'post_category' => wp_get_post_categories( $source->ID ),
		'tags_input'    => wp_get_post_tags( $source->ID, array( 'fields' => 'names' ) ),
		'meta_input'    => array(
			TSUKURU_CLAUDE_DRAFTS_REWRITE_META => $source->ID,
		),
	);

	$post_id = wp_insert_post( wp_slash( $postarr ), true );
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	$data                    = tsukuru_claude_drafts_format( $post_id );
	$data['rewrite_of']      = (int) $source->ID;
	$data['source_link']     = get_permalink( $source );
	$data['source_edit_url'] = admin_url( 'post.php?post=' . $source->ID . '&action=edit' );
	return $data;
}

/**
 * 下書き作成
 *
 * @param array $input 入力値。
 * @return array|WP_Error
 */
function tsukuru_claude_drafts_create( $input = array() ) {
	$postarr                = tsukuru_claude_drafts_build_postarr( (array) $input );
	$postarr['post_type']   = 'post';
	$postarr['post_status'] = 'draft'; // 常に下書き固定
	$postarr['post_author'] = get_current_user_id();

	$post_id = wp_insert_post( wp_slash( $postarr ), true );
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}
	return tsukuru_claude_drafts_format( $post_id );
}

/**
 * 下書き更新
 *
 * @param array $input 入力値。
 * @return array|WP_Error
 */
function tsukuru_claude_drafts_update( $input = array() ) {
	$input   = (array) $input;
	$post_id = isset( $input['id'] ) ? (int) $input['id'] : 0;
	$post    = get_post( $post_id );

	if ( ! $post || 'post' !== $post->post_type ) {
		return new WP_Error( 'not_found', '指定されたIDの投稿が見つかりません。' );
	}
	if ( ! in_array( $post->post_status, array( 'draft', 'pending', 'auto-draft' ), true ) ) {
		return new WP_Error( 'not_draft', '下書き・レビュー待ち以外の記事は更新できません。' );
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'forbidden', 'この投稿を編集する権限がありません。' );
	}

	$postarr                = tsukuru_claude_drafts_build_postarr( $input );
	$postarr['ID']          = $post_id;
	$postarr['post_status'] = 'auto-draft' === $post->post_status ? 'draft' : $post->post_status;

	$result = wp_update_post( wp_slash( $postarr ), true );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return tsukuru_claude_drafts_format( $post_id );
}

/**
 * 下書き一覧
 *
 * @param array $input 入力値。
 * @return array
 */
function tsukuru_claude_drafts_list( $input = array() ) {
	$input = (array) $input;
	$limit = isset( $input['limit'] ) ? min( 50, max( 1, (int) $input['limit'] ) ) : 20;

	$args = array(
		'post_type'      => 'post',
		'post_status'    => array( 'draft', 'pending' ),
		'posts_per_page' => $limit,
		'orderby'        => 'modified',
		'order'          => 'DESC',
		'fields'         => 'ids',
	);
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		$args['author'] = get_current_user_id();
	}

	return array_map( 'tsukuru_claude_drafts_format', get_posts( $args ) );
}

/**
 * カテゴリ一覧
 *
 * @return array
 */
function tsukuru_claude_drafts_categories() {
	$terms = get_terms(
		array(
			'taxonomy'   => 'category',
			'hide_empty' => false,
		)
	);
	if ( is_wp_error( $terms ) ) {
		return array();
	}
	return array_map(
		function ( $term ) {
			return array(
				'id'   => (int) $term->term_id,
				'name' => $term->name,
				'slug' => $term->slug,
			);
		},
		$terms
	);
}
