<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

/**
 * Custom artifact lifecycle statuses.
 *
 * Registers three non-public statuses alongside WP core draft/publish:
 *   wm_review   — In Review (shared with a client, awaiting feedback)
 *   wm_approved — Approved   (signed off, not yet live)
 *   wm_archived — Archived   (retired, kept for reference)
 *
 * The block editor picks up status changes via the LifecycleStatusPanel in
 * document-panels.js, which calls wp.data.dispatch('core/editor').editPost().
 * Classic-editor admins get a status dropdown injected via admin_footer JS.
 */
class LifecycleStatus {

	const IN_REVIEW = 'wm_review';
	const APPROVED  = 'wm_approved';
	const ARCHIVED  = 'wm_archived';

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register_statuses' ) );
		add_action( 'admin_footer-post.php', array( $this, 'inject_status_js' ) );
		add_action( 'admin_footer-post-new.php', array( $this, 'inject_status_js' ) );
		// Include custom statuses in default REST collection queries for editors.
		add_filter( 'rest_' . PostType::KEY . '_query', array( $this, 'include_custom_statuses_in_query' ), 10, 2 );
	}

	public function register_statuses(): void {
		register_post_status(
			self::IN_REVIEW,
			array(
				'label'                     => _x( 'In Review', 'post status', 'webmultipliers-wp-artifact-canvas' ),
				'public'                    => false,
				'internal'                  => false,
				'protected'                 => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: count placeholder */
				'label_count'               => _n_noop(
					'In Review <span class="count">(%s)</span>',
					'In Review <span class="count">(%s)</span>',
					'webmultipliers-wp-artifact-canvas'
				),
			)
		);

		register_post_status(
			self::APPROVED,
			array(
				'label'                     => _x( 'Approved', 'post status', 'webmultipliers-wp-artifact-canvas' ),
				'public'                    => false,
				'internal'                  => false,
				'protected'                 => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: count placeholder */
				'label_count'               => _n_noop(
					'Approved <span class="count">(%s)</span>',
					'Approved <span class="count">(%s)</span>',
					'webmultipliers-wp-artifact-canvas'
				),
			)
		);

		register_post_status(
			self::ARCHIVED,
			array(
				'label'                     => _x( 'Archived', 'post status', 'webmultipliers-wp-artifact-canvas' ),
				'public'                    => false,
				'internal'                  => false,
				'protected'                 => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: count placeholder */
				'label_count'               => _n_noop(
					'Archived <span class="count">(%s)</span>',
					'Archived <span class="count">(%s)</span>',
					'webmultipliers-wp-artifact-canvas'
				),
			)
		);
	}

	/**
	 * Injects custom statuses into the classic post-edit status dropdown
	 * for environments that still render the classic metaboxes.
	 */
	public function inject_status_js(): void {
		global $post;
		if ( ! $post || $post->post_type !== PostType::KEY ) {
			return;
		}

		$statuses = self::get_all();
		$current  = $post->post_status;

		// Build <option> HTML for the statuses that aren't already in the dropdown.
		$options_html = '';
		foreach ( $statuses as $slug => $label ) {
			$selected      = selected( $current, $slug, false );
			$options_html .= sprintf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $slug ),
				$selected,
				esc_html( $label )
			);
		}

		$current_label = $statuses[ $current ] ?? '';
		?>
		<script>
		(function(){
			var sel = document.getElementById('post_status');
			if (!sel) return;
			sel.insertAdjacentHTML('beforeend', <?php echo wp_json_encode( $options_html ); ?>);
			<?php if ( $current_label ) : ?>
			var display = document.getElementById('post-status-display');
			if (display) display.textContent = <?php echo wp_json_encode( $current_label ); ?>;
			sel.value = <?php echo wp_json_encode( $current ); ?>;
			<?php endif; ?>
		})();
		</script>
		<?php
	}

	/**
	 * Expands default REST collection queries to include the custom lifecycle
	 * statuses, so the block editor's link/search UIs and headless clients can
	 * see artifacts sitting in wm_review / wm_approved / wm_archived without
	 * passing an explicit status filter. Applies only to requesters who can
	 * edit artifacts; explicit ?status= filters are respected as-is (their
	 * values already validate — registered statuses are in the param enum).
	 *
	 * @param array<string, mixed>   $args    WP_Query args prepared by the REST controller.
	 * @param \WP_REST_Request|null $request The originating REST request.
	 * @return array<string, mixed>
	 */
	public function include_custom_statuses_in_query( array $args, $request = null ): array {
		if ( $request instanceof \WP_REST_Request && $request->get_param( 'status' ) !== null ) {
			return $args;
		}

		$post_type_object = get_post_type_object( PostType::KEY );
		$edit_cap         = $post_type_object ? $post_type_object->cap->edit_posts : 'edit_posts';
		if ( ! current_user_can( $edit_cap ) ) {
			return $args;
		}

		$statuses            = (array) ( $args['post_status'] ?? array( 'publish' ) );
		$args['post_status'] = array_values( array_unique( array_merge( $statuses, array_keys( self::get_all() ) ) ) );

		return $args;
	}

	/** Returns all custom lifecycle status slugs → labels. */
	public static function get_all(): array {
		return array(
			self::IN_REVIEW => _x( 'In Review', 'post status', 'webmultipliers-wp-artifact-canvas' ),
			self::APPROVED  => _x( 'Approved', 'post status', 'webmultipliers-wp-artifact-canvas' ),
			self::ARCHIVED  => _x( 'Archived', 'post status', 'webmultipliers-wp-artifact-canvas' ),
		);
	}
}
