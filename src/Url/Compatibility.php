<?php
declare( strict_types = 1 );

namespace PostDomain\Url;

/**
 * The matrix the integration suite iterates, so the documented surface and the
 * tested surface cannot drift apart.
 */
final class Compatibility {

	/** @var array<int, array{surface: string, hook: string, rebased: bool}> */
	public const SURFACES = array(
		array(
			'surface' => 'home_url',
			'hook'    => 'home_url',
			'rebased' => true,
		),
		array(
			'surface' => 'site_url',
			'hook'    => 'site_url',
			'rebased' => false,
		),
		array(
			'surface' => 'post permalink',
			'hook'    => 'post_link',
			'rebased' => true,
		),
		array(
			'surface' => 'page permalink',
			'hook'    => 'page_link',
			'rebased' => true,
		),
		array(
			'surface' => 'custom type permalink',
			'hook'    => 'post_type_link',
			'rebased' => true,
		),
		array(
			'surface' => 'attachment',
			'hook'    => 'attachment_link',
			'rebased' => true,
		),
		array(
			'surface' => 'term',
			'hook'    => 'term_link',
			'rebased' => true,
		),
		array(
			'surface' => 'rest root',
			'hook'    => 'rest_url',
			'rebased' => true,
		),
		array(
			'surface' => 'admin-ajax',
			'hook'    => 'admin_url',
			'rebased' => true,
		),
		array(
			'surface' => 'comment form',
			'hook'    => 'comment_form_defaults',
			'rebased' => true,
		),
		array(
			'surface' => 'comment redirect',
			'hook'    => 'comment_post_redirect',
			'rebased' => true,
		),
		array(
			'surface' => 'feed',
			'hook'    => 'feed_link',
			'rebased' => true,
		),
		array(
			'surface' => 'comments feed',
			'hook'    => 'post_comments_feed_link',
			'rebased' => true,
		),
		array(
			'surface' => 'oembed',
			'hook'    => 'oembed_response_data',
			'rebased' => true,
		),
		array(
			'surface' => 'embed html',
			'hook'    => 'embed_html',
			'rebased' => true,
		),
		array(
			'surface' => 'sitemap',
			'hook'    => 'wp_sitemaps_index_entry',
			'rebased' => true,
		),
		array(
			'surface' => 'shortlink',
			'hook'    => 'get_shortlink',
			'rebased' => true,
		),
		array(
			'surface' => 'home option',
			'hook'    => 'pre_option_home',
			'rebased' => false,
		),
	);
}
