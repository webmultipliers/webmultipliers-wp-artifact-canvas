<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

/**
 * Branded PDF viewer — serves .pdf artifacts via a PDF.js HTML shell.
 *
 * When an artifact's format is set to 'pdf' (via _wmac_format meta), the
 * Renderer delegates to this class instead of echoing raw HTML. A self-contained
 * HTML page embedding Mozilla PDF.js is served at the artifact's URL; the PDF
 * binary is streamed through a separate REST endpoint so that password protection
 * and expiry rules apply to both the viewer shell and the PDF bytes.
 *
 * Upload flow: POST /wmac/v1/artifacts/{id}/pdf-file (multipart, field: file)
 * View flow:   Renderer → PdfRenderer::render() → browser loads PDF.js shell
 *              PDF.js fetches PDF binary from GET /wmac/v1/artifacts/{id}/pdf
 *
 * Filters:
 *   wmac_pdfjs_url        (string) — CDN URL for pdf.min.mjs
 *   wmac_pdfjs_worker_url (string) — CDN URL for pdf.worker.min.mjs
 *   wmac_max_pdf_size     (int)    — max upload bytes, default 50 MB
 */
class PdfRenderer {

	const FORMAT_META  = '_wmac_format';
	const FORMAT_PDF   = 'pdf';

	const PDFJS_URL    = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.3.136/pdf.min.mjs';
	const PDFJS_WORKER = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.3.136/pdf.worker.min.mjs';

	public function register_hooks(): void {
		add_action( 'init', [ $this, 'register_meta' ] );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_meta(): void {
		register_post_meta( PostType::KEY, self::FORMAT_META, [
			'type'              => 'string',
			'description'       => 'Artifact format: empty/"html" = passthrough HTML (default), "pdf" = PDF viewer.',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => static function ( $v ): string {
				return in_array( (string) $v, [ '', 'html', 'pdf' ], true ) ? (string) $v : '';
			},
			'auth_callback' => static function ( bool $allowed, string $meta_key, int $post_id ): bool {
				return current_user_can( 'edit_post', $post_id );
			},
		] );
	}

	public function register_routes(): void {
		$id_arg = [
			'id' => [
				'required'          => true,
				'validate_callback' => static function ( $v ): bool {
					return is_numeric( $v ) && (int) $v > 0;
				},
				'sanitize_callback' => 'absint',
			],
		];

		// Serve the raw PDF bytes (password-gated).
		register_rest_route( 'wmac/v1', '/artifacts/(?P<id>[\d]+)/pdf', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'rest_serve_pdf' ],
			'permission_callback' => '__return_true',
			'args'                => $id_arg,
		] );

		// Upload a PDF file and set the format flag.
		register_rest_route( 'wmac/v1', '/artifacts/(?P<id>[\d]+)/pdf-file', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'rest_upload_pdf' ],
			'permission_callback' => [ $this, 'check_edit_permission' ],
			'args'                => $id_arg,
		] );

		// Remove the PDF file and revert to HTML mode.
		register_rest_route( 'wmac/v1', '/artifacts/(?P<id>[\d]+)/pdf-file', [
			'methods'             => \WP_REST_Server::DELETABLE,
			'callback'            => [ $this, 'rest_delete_pdf' ],
			'permission_callback' => [ $this, 'check_edit_permission' ],
			'args'                => $id_arg,
		] );
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/** Returns true when this artifact should be served via the PDF viewer. */
	public function is_pdf_artifact( \WP_Post $post ): bool {
		$format = get_post_meta( $post->ID, self::FORMAT_META, true );
		if ( $format === self::FORMAT_PDF ) {
			return true;
		}
		// Auto-detect: a .pdf file on disk without an explicit format meta.
		$path = self::get_pdf_path( $post->ID );
		return $path !== null && is_readable( $path );
	}

	/** Outputs the PDF.js viewer HTML shell and exits. */
	public function render( \WP_Post $post ): void {
		$charset   = esc_attr( get_option( 'blog_charset' ) ?: 'UTF-8' );
		$title     = esc_html( get_the_title( $post ) );
		$lang      = esc_attr( get_bloginfo( 'language' ) );
		$pdfjs     = esc_url( (string) apply_filters( 'wmac_pdfjs_url', self::PDFJS_URL ) );
		$pdfjs_w   = esc_url( (string) apply_filters( 'wmac_pdfjs_worker_url', self::PDFJS_WORKER ) );
		$pdf_url   = esc_url( rest_url( 'wmac/v1/artifacts/' . $post->ID . '/pdf' ) );
		$logo_html = function_exists( 'get_custom_logo' ) ? get_custom_logo() : '';

		status_header( 200 );
		header( "Content-Type: text/html; charset={$charset}" );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: no-store' );
		header( 'X-Robots-Tag: noindex,nofollow' );

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo <<<HTML
		<!doctype html>
		<html lang="{$lang}">
		<head>
		<meta charset="{$charset}">
		<meta name="viewport" content="width=device-width,initial-scale=1">
		<meta name="robots" content="noindex,nofollow">
		<title>{$title}</title>
		<style>
		*{box-sizing:border-box;margin:0;padding:0}
		body{background:#404040;font-family:system-ui,sans-serif;min-height:100vh;display:flex;flex-direction:column;align-items:center}
		#wmac-pdf-bar{width:100%;max-width:960px;background:#323639;color:#f0f0f0;padding:10px 16px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:13px;position:sticky;top:0;z-index:100}
		#wmac-pdf-bar .logo img{max-height:28px;width:auto;display:block}
		#wmac-pdf-title{flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:600}
		.wmac-btn{background:#555;color:#fff;border:none;border-radius:4px;padding:5px 12px;cursor:pointer;font-size:12px;line-height:1;white-space:nowrap}
		.wmac-btn:hover{background:#666}.wmac-btn:disabled{opacity:.45;cursor:default}
		#wmac-pdf-pages{display:flex;align-items:center;gap:6px;font-size:12px}
		#wmac-pdf-wrap{width:100%;max-width:960px;background:#fff;margin:0 auto}
		canvas{display:block;width:100%!important;height:auto!important}
		#wmac-pdf-loading{color:#ccc;padding:40px;text-align:center;font-size:14px}
		</style>
		</head>
		<body>
		<div id="wmac-pdf-bar">
		  {$logo_html}
		  <span id="wmac-pdf-title">{$title}</span>
		  <div id="wmac-pdf-pages">
		    <button class="wmac-btn" id="wmac-prev" disabled>&#8592;</button>
		    <span>Page <strong id="wmac-cur">1</strong> of <strong id="wmac-total">&#8230;</strong></span>
		    <button class="wmac-btn" id="wmac-next" disabled>&#8594;</button>
		  </div>
		  <a class="wmac-btn" href="{$pdf_url}" download>&#8595;&nbsp;Download</a>
		</div>
		<div id="wmac-pdf-wrap"><p id="wmac-pdf-loading">Loading&hellip;</p></div>
		<script type="module">
		import * as pdfjsLib from '{$pdfjs}';
		pdfjsLib.GlobalWorkerOptions.workerSrc = '{$pdfjs_w}';

		const wrap  = document.getElementById('wmac-pdf-wrap');
		const curEl = document.getElementById('wmac-cur');
		const totEl = document.getElementById('wmac-total');
		const prev  = document.getElementById('wmac-prev');
		const next  = document.getElementById('wmac-next');
		let pdf = null, pageNum = 1, rendering = false, queued = null;

		function renderPage(n) {
		  rendering = true;
		  pdf.getPage(n).then(page => {
		    const vp = page.getViewport({ scale: Math.min(960, wrap.clientWidth) / page.getViewport({scale:1}).width });
		    const canvas = document.createElement('canvas');
		    canvas.height = vp.height;
		    canvas.width  = vp.width;
		    wrap.innerHTML = '';
		    wrap.appendChild(canvas);
		    return page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
		  }).then(() => {
		    rendering = false;
		    curEl.textContent = n;
		    prev.disabled = n <= 1;
		    next.disabled = n >= pdf.numPages;
		    if (queued !== null) { const q = queued; queued = null; pageNum = q; renderPage(q); }
		  });
		}

		function go(n) {
		  if (!pdf || n < 1 || n > pdf.numPages) return;
		  if (rendering) { queued = n; return; }
		  pageNum = n; renderPage(n);
		}

		pdfjsLib.getDocument('{$pdf_url}').promise.then(doc => {
		  pdf = doc;
		  totEl.textContent = doc.numPages;
		  document.getElementById('wmac-pdf-loading')?.remove();
		  renderPage(1);
		}).catch(() => {
		  wrap.innerHTML = '<p style="padding:40px;color:#c00;text-align:center">Could not load PDF.</p>';
		});

		prev.addEventListener('click', () => go(pageNum - 1));
		next.addEventListener('click', () => go(pageNum + 1));
		document.addEventListener('keydown', e => {
		  if (e.key === 'ArrowRight' || e.key === 'ArrowDown')  go(pageNum + 1);
		  if (e.key === 'ArrowLeft'  || e.key === 'ArrowUp')    go(pageNum - 1);
		});
		</script>
		</body>
		</html>
		HTML;
		// phpcs:enable
	}

	// -------------------------------------------------------------------------
	// REST callbacks
	// -------------------------------------------------------------------------

	public function rest_serve_pdf( \WP_REST_Request $request ): void {
		$post_id = absint( $request->get_param( 'id' ) );
		$post    = get_post( $post_id );

		if ( ! $post || $post->post_type !== PostType::KEY || $post->post_status !== 'publish' ) {
			status_header( 404 );
			exit;
		}

		// Password gate: check cookie that WP sets after the form is submitted.
		if ( post_password_required( $post ) ) {
			$hash     = $post->post_password;
			$cookie   = isset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] )
				? wp_unslash( (string) $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] )
				: '';
			if ( ! $cookie || ! wp_check_password( $cookie, $hash ) ) {
				status_header( 401 );
				exit;
			}
		}

		$pdf_path = self::get_pdf_path( $post_id );
		if ( ! $pdf_path || ! is_readable( $pdf_path ) ) {
			status_header( 404 );
			exit;
		}

		$size = filesize( $pdf_path );
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="artifact-' . $post_id . '.pdf"' );
		header( 'Cache-Control: no-store' );
		header( 'X-Robots-Tag: noindex,nofollow' );
		if ( $size !== false ) {
			header( 'Content-Length: ' . $size );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $pdf_path );
		exit;
	}

	public function rest_upload_pdf( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = absint( $request->get_param( 'id' ) );
		$files   = $request->get_file_params();

		if ( empty( $files['file'] ) || $files['file']['error'] !== UPLOAD_ERR_OK ) {
			return new \WP_Error( 'wmac_no_file', __( 'No valid file provided.', 'webmultipliers-wp-artifact-canvas' ), [ 'status' => 400 ] );
		}

		$file = $files['file'];
		$ext  = strtolower( (string) pathinfo( $file['name'], PATHINFO_EXTENSION ) );

		if ( $ext !== 'pdf' ) {
			return new \WP_Error( 'wmac_invalid_type', __( 'Only .pdf files are accepted.', 'webmultipliers-wp-artifact-canvas' ), [ 'status' => 400 ] );
		}

		$max_bytes = (int) apply_filters( 'wmac_max_pdf_size', 50 * 1024 * 1024 );
		if ( (int) $file['size'] > $max_bytes ) {
			return new \WP_Error( 'wmac_file_too_large', __( 'File exceeds the maximum allowed size.', 'webmultipliers-wp-artifact-canvas' ), [ 'status' => 413 ] );
		}

		// Validate PDF magic bytes (%PDF).
		$fh = fopen( $file['tmp_name'], 'rb' );
		if ( $fh === false ) {
			return new \WP_Error( 'wmac_read_failed', __( 'Could not open the uploaded file.', 'webmultipliers-wp-artifact-canvas' ), [ 'status' => 500 ] );
		}
		$magic = fread( $fh, 4 );
		fclose( $fh );
		if ( $magic !== '%PDF' ) {
			return new \WP_Error( 'wmac_invalid_pdf', __( 'Uploaded file is not a valid PDF (%PDF magic bytes missing).', 'webmultipliers-wp-artifact-canvas' ), [ 'status' => 400 ] );
		}

		$dir = ArtifactFile::get_or_create_upload_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$dest = $dir . DIRECTORY_SEPARATOR . $post_id . '.pdf';
		if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
			return new \WP_Error( 'wmac_upload_failed', __( 'Could not save the PDF file.', 'webmultipliers-wp-artifact-canvas' ), [ 'status' => 500 ] );
		}

		update_post_meta( $post_id, self::FORMAT_META, self::FORMAT_PDF );

		return new \WP_REST_Response( [ 'stored' => true ], 200 );
	}

	public function rest_delete_pdf( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id  = absint( $request->get_param( 'id' ) );
		$pdf_path = self::get_pdf_path( $post_id );

		if ( $pdf_path !== null && file_exists( $pdf_path ) ) {
			wp_delete_file( $pdf_path );
		}

		delete_post_meta( $post_id, self::FORMAT_META );

		return new \WP_REST_Response( [ 'removed' => true ], 200 );
	}

	public function check_edit_permission( \WP_REST_Request $request ): bool|\WP_Error {
		$post_id = absint( $request->get_param( 'id' ) );
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'wmac_forbidden', __( 'Insufficient permissions.', 'webmultipliers-wp-artifact-canvas' ), [ 'status' => 403 ] );
		}
		return true;
	}

	// -------------------------------------------------------------------------
	// Static helpers
	// -------------------------------------------------------------------------

	public static function get_pdf_path( int $post_id ): ?string {
		$upload_dir = wp_upload_dir();
		if ( $upload_dir['error'] ) {
			return null;
		}
		return $upload_dir['basedir']
			. DIRECTORY_SEPARATOR . 'wmac-artifacts'
			. DIRECTORY_SEPARATOR . $post_id . '.pdf';
	}
}
