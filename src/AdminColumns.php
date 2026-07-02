<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

/**
 * Customises the wm_artifact post list table:
 * - "Size" column — byte count of the HTML payload (file or block attribute).
 * - "Flags" column — noindex / CSP badges when those features are active.
 * - "Download" row action — delivers the HTML with Content-Disposition: attachment.
 */
class AdminColumns {

	public function register_hooks(): void {
		add_filter( 'manage_' . PostType::KEY . '_posts_columns', array( $this, 'add_columns' ) );
		add_action( 'manage_' . PostType::KEY . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-' . PostType::KEY . '_sortable_columns', array( $this, 'sortable_columns' ) );
		add_action( 'pre_get_posts', array( $this, 'handle_orderby' ) );
		add_filter( 'post_row_actions', array( $this, 'add_row_actions' ), 10, 2 );
		add_action( 'admin_post_wmac_download', array( $this, 'handle_download' ) );
		add_action( 'admin_head', array( $this, 'column_styles' ) );
	}

	public function add_columns( array $columns ): array {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( $key === 'title' ) {
				$new['wmac_size']  = __( 'Size', 'webmultipliers-wp-artifact-canvas' );
				$new['wmac_flags'] = __( 'Flags', 'webmultipliers-wp-artifact-canvas' );
			}
		}
		return $new;
	}

	public function render_column( string $column, int $post_id ): void {
		if ( $column === 'wmac_size' ) {
			$bytes = $this->get_html_byte_size( $post_id );
			if ( $bytes === null ) {
				echo '<span aria-hidden="true">—</span>';
				return;
			}
			echo '<span title="' . esc_attr( number_format( $bytes ) . ' bytes' ) . '">'
				. esc_html( $this->format_bytes( $bytes ) )
				. '</span>';
			return;
		}

		if ( $column === 'wmac_flags' ) {
			$this->render_flags( $post_id );
		}
	}

	public function sortable_columns( array $columns ): array {
		$columns['wmac_size'] = 'wmac_size';
		return $columns;
	}

	public function handle_orderby( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( $query->get( 'orderby' ) !== 'wmac_size' ) {
			return;
		}
		// Size isn't stored as meta, so we can't sort it at the DB level.
		// Silently fall back to date order — sorting 500+ rows in PHP is not worth it.
		$query->set( 'orderby', 'date' );
	}

	public function add_row_actions( array $actions, \WP_Post $post ): array {
		if ( $post->post_type !== PostType::KEY ) {
			return $actions;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=wmac_download&post=' . $post->ID ),
			'wmac_download_' . $post->ID
		);

		$actions['wmac_download'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Download HTML', 'webmultipliers-wp-artifact-canvas' )
		);

		return $actions;
	}

	public function handle_download(): void {
		$post_id = absint( $_GET['post'] ?? 0 );

		if ( ! $post_id ) {
			wp_die( esc_html__( 'Invalid request.', 'webmultipliers-wp-artifact-canvas' ), 400 );
		}

		check_admin_referer( 'wmac_download_' . $post_id );

		$post = get_post( $post_id );
		if ( ! $post || $post->post_type !== PostType::KEY ) {
			wp_die( esc_html__( 'Artifact not found.', 'webmultipliers-wp-artifact-canvas' ), 404 );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You do not have permission to download this artifact.', 'webmultipliers-wp-artifact-canvas' ), 403 );
		}

		$html = $this->get_html( $post );
		if ( $html === '' ) {
			wp_die( esc_html__( 'This artifact has no HTML content to download.', 'webmultipliers-wp-artifact-canvas' ), 404 );
		}

		$filename = sanitize_file_name( get_the_title( $post ) ?: 'artifact-' . $post_id ) . '.html';

		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $html ) );
		header( 'Cache-Control: no-store' );
		header( 'X-Content-Type-Options: nosniff' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $html;
		exit;
	}

	public function column_styles(): void {
		$screen = get_current_screen();
		if ( ! $screen || $screen->id !== 'edit-' . PostType::KEY ) {
			return;
		}
		echo '<style>
			.column-wmac_size  { width: 80px; white-space: nowrap; }
			.column-wmac_flags { width: 110px; }
			.wmac-badge {
				display: inline-block;
				padding: 1px 6px;
				border-radius: 3px;
				font-size: 11px;
				font-weight: 600;
				line-height: 1.6;
				margin: 1px 2px 1px 0;
			}
			.wmac-badge--noindex { background: #fef3cd; color: #856404; }
			.wmac-badge--csp     { background: #d1ecf1; color: #0c5460; }
		</style>';
	}

	// --- Helpers ---

	private function render_flags( int $post_id ): void {
		$post   = get_post( $post_id );
		$output = '';

		$noindex = (bool) apply_filters( 'wmac_noindex', true, $post );
		if ( $noindex ) {
			$output .= '<span class="wmac-badge wmac-badge--noindex" title="'
				. esc_attr__( 'Search engines are discouraged from indexing this artifact', 'webmultipliers-wp-artifact-canvas' )
				. '">' . esc_html__( 'noindex', 'webmultipliers-wp-artifact-canvas' ) . '</span>';
		}

		$csp = (string) apply_filters( 'wmac_csp', '', $post );
		if ( $csp !== '' ) {
			$output .= '<span class="wmac-badge wmac-badge--csp" title="'
				. esc_attr( substr( $csp, 0, 80 ) . ( strlen( $csp ) > 80 ? '…' : '' ) )
				. '">' . esc_html__( 'CSP', 'webmultipliers-wp-artifact-canvas' ) . '</span>';
		}

		echo $output !== '' ? $output : '<span aria-hidden="true">—</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private function get_html_byte_size( int $post_id ): ?int {
		$file_path = ArtifactFile::get_file_path( $post_id );
		if ( $file_path !== null && is_readable( $file_path ) ) {
			$size = filesize( $file_path );
			return $size !== false ? $size : null;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}

		$blocks = parse_blocks( $post->post_content );
		foreach ( $blocks as $block ) {
			if ( $block['blockName'] === 'wmac/artifact' ) {
				$html = $block['attrs']['html'] ?? '';
				return $html !== '' ? strlen( $html ) : null;
			}
		}
		return null;
	}

	private function get_html( \WP_Post $post ): string {
		$file_path = ArtifactFile::get_file_path( $post->ID );
		if ( $file_path !== null && is_readable( $file_path ) ) {
			$content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local artifact file, not remote.
			if ( $content !== false ) {
				return $content;
			}
		}

		$blocks = parse_blocks( $post->post_content );
		foreach ( $blocks as $block ) {
			if ( $block['blockName'] === 'wmac/artifact' ) {
				return $block['attrs']['html'] ?? '';
			}
		}
		return '';
	}

	private function format_bytes( int $bytes ): string {
		if ( $bytes >= 1024 * 1024 ) {
			return round( $bytes / ( 1024 * 1024 ), 1 ) . ' MB';
		}
		if ( $bytes >= 1024 ) {
			return round( $bytes / 1024, 1 ) . ' KB';
		}
		return $bytes . ' B';
	}
}
