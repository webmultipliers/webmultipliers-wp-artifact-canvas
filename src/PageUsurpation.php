<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

/**
 * Direct core page usurpation — lets a standard WP Page hand its URL to an artifact.
 *
 * Editors set _wmac_usurp_artifact_id on any WP Page via the "Artifact Usurpation"
 * metabox. When that page is requested, this class intercepts template_redirect
 * (priority 5, before Renderer's priority 1 fires on the artifact itself) and
 * serves the artifact's HTML at the page's canonical URL.
 *
 * Security: checks password gate on the artifact (not the page). Runs the full
 * wmac_rendered_html filter chain so AssetMapper, MergeTags, ClientTracking, and
 * LinkGovernance all apply.
 *
 * Design note: hooks template_redirect (not do_parse_request) so WordPress still
 * resolves the page normally — the Page record, its slug, and its permalink are
 * preserved in the database. ArtifactAlias (do_parse_request) is unaffected.
 */
class PageUsurpation {

	const META_KEY = '_wmac_usurp_artifact_id';

	public function register_hooks(): void {
		add_action( 'init', [ $this, 'register_meta' ] );
		add_action( 'template_redirect', [ $this, 'maybe_usurp' ], 5 );
		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
		add_action( 'save_post_page', [ $this, 'save_meta_box' ] );
	}

	public function register_meta(): void {
		register_post_meta( 'page', self::META_KEY, [
			'type'              => 'integer',
			'description'       => 'Artifact ID whose HTML should be served at this page\'s URL.',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'absint',
			'auth_callback'     => static function ( bool $allowed, string $meta_key, int $post_id ): bool {
				return current_user_can( 'edit_post', $post_id );
			},
		] );
	}

	public function maybe_usurp(): void {
		if ( ! is_singular( 'page' ) ) {
			return;
		}

		$page = get_queried_object();
		if ( ! $page instanceof \WP_Post ) {
			return;
		}

		$artifact_id = (int) get_post_meta( $page->ID, self::META_KEY, true );
		if ( $artifact_id <= 0 ) {
			return;
		}

		$artifact = get_post( $artifact_id );
		if ( ! $artifact || $artifact->post_type !== PostType::KEY || $artifact->post_status !== 'publish' ) {
			return;
		}

		// Password gate on the artifact.
		if ( post_password_required( $artifact ) ) {
			remove_action( 'template_redirect', 'redirect_canonical' );
			( new PasswordView() )->render( $artifact );
			exit;
		}

		// Expiry gate.
		$governance = new LinkGovernance();
		if ( $governance->is_expired( $artifact ) ) {
			remove_action( 'template_redirect', 'redirect_canonical' );
			$governance->render_expired_view( $artifact );
			exit;
		}

		$html = $this->get_artifact_html( $artifact );
		if ( $html === '' ) {
			return;
		}

		remove_action( 'template_redirect', 'redirect_canonical' );

		do_action( 'wmac_artifact_served', $artifact );

		$html = (string) apply_filters( 'wmac_rendered_html', $html, $artifact );

		$this->send_headers( $artifact );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public function add_meta_box(): void {
		add_meta_box(
			'wmac_page_usurpation',
			__( 'Artifact Usurpation', 'webmultipliers-wp-artifact-canvas' ),
			[ $this, 'render_meta_box' ],
			'page',
			'side',
			'default'
		);
	}

	public function render_meta_box( \WP_Post $post ): void {
		$artifact_id = (int) get_post_meta( $post->ID, self::META_KEY, true );
		wp_nonce_field( 'wmac_usurp_save', 'wmac_usurp_nonce' );

		$artifacts = get_posts( [
			'post_type'      => PostType::KEY,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );

		echo '<p><select name="wmac_usurp_artifact_id" style="width:100%">';
		echo '<option value="0">' . esc_html__( '— None (serve page normally) —', 'webmultipliers-wp-artifact-canvas' ) . '</option>';
		foreach ( $artifacts as $art ) {
			printf(
				'<option value="%d" %s>%s</option>',
				esc_attr( $art->ID ),
				selected( $artifact_id, $art->ID, false ),
				esc_html( $art->post_title )
			);
		}
		echo '</select></p>';
		echo '<p class="description">' . esc_html__( 'Serve the selected artifact\'s HTML at this page\'s URL instead of the page content.', 'webmultipliers-wp-artifact-canvas' ) . '</p>';
	}

	public function save_meta_box( int $post_id ): void {
		if ( ! isset( $_POST['wmac_usurp_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( (string) $_POST['wmac_usurp_nonce'] ), 'wmac_usurp_save' )
		) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$value = absint( $_POST['wmac_usurp_artifact_id'] ?? 0 );
		if ( $value > 0 ) {
			update_post_meta( $post_id, self::META_KEY, $value );
		} else {
			delete_post_meta( $post_id, self::META_KEY );
		}
	}

	private function get_artifact_html( \WP_Post $artifact ): string {
		$file_path = ArtifactFile::get_file_path( $artifact->ID );
		if ( $file_path !== null && is_readable( $file_path ) ) {
			$content = file_get_contents( $file_path );
			if ( $content !== false ) {
				return $content;
			}
		}

		$blocks = parse_blocks( $artifact->post_content );
		foreach ( $blocks as $block ) {
			if ( $block['blockName'] === 'wmac/artifact' ) {
				return $block['attrs']['html'] ?? '';
			}
		}
		return '';
	}

	private function send_headers( \WP_Post $post ): void {
		$charset = esc_attr( get_option( 'blog_charset' ) ?: 'UTF-8' );
		status_header( 200 );
		header( "Content-Type: text/html; charset={$charset}" );
		header( 'X-Content-Type-Options: nosniff' );
		// Usurped pages are always private-ish (never edge-cached under the page URL).
		header( 'Cache-Control: no-store, no-cache, must-revalidate' );
		header( 'Pragma: no-cache' );
		$noindex = (bool) apply_filters( 'wmac_noindex', true, $post );
		if ( $noindex ) {
			header( 'X-Robots-Tag: noindex, nofollow' );
		}
	}
}
