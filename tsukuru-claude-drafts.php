<?php
/**
 * Plugin Name:       Tsukuru Claude Drafts
 * Description:       Claude（MCP Adapter経由）から記事の下書きを作成・更新するためのアビリティを登録します。公開はできません。
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            やまはる
 * License:           GPL-2.0-or-later
 * Text Domain:       tsukuru-claude-drafts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * このプラグインでできること（すべて「下書き」止まり）
 * - tsukuru/create-draft    … 新しい下書きを作成
 * - tsukuru/update-draft    … 下書き・レビュー待ちの記事を更新
 * - tsukuru/list-drafts     … 自分の下書き一覧を取得
 * - tsukuru/list-categories … カテゴリ一覧を取得
 *
 * 公開・予約投稿・削除はできない設計です。
 * 必要な権限は edit_posts（寄稿者以上）。Claude用ユーザーは「寄稿者」推奨。
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
				'id'       => array( 'type' => 'integer' ),
				'title'    => array( 'type' => 'string' ),
				'status'   => array( 'type' => 'string' ),
				'edit_url' => array( 'type' => 'string' ),
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
	return array(
		'id'       => (int) $post->ID,
		'title'    => $post->post_title,
		'status'   => $post->post_status,
		'edit_url' => admin_url( 'post.php?post=' . $post->ID . '&action=edit' ),
	);
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
