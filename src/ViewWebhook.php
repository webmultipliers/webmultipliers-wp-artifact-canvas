<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

/**
 * Fires an outbound webhook when an artifact is publicly served.
 *
 * Stores a URL in _wmac_view_webhook_url. On each public serve (via the
 * wmac_artifact_served action), a non-blocking JSON POST is sent to that URL
 * with a structured event payload.
 *
 * Intended for Slack/Discord/Zapier integrations so agencies know the moment
 * a client opens a password-protected or time-limited preview.
 *
 * Filter wmac_view_webhook_payload to add custom fields.
 * Filter wmac_view_webhook_sslverify (bool) to disable TLS verification on dev.
 */
class ViewWebhook {

	const META_KEY = '_wmac_view_webhook_url';

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'wmac_artifact_served', array( $this, 'fire_webhook' ) );
	}

	public function register_meta(): void {
		register_post_meta(
			PostType::KEY,
			self::META_KEY,
			array(
				'type'              => 'string',
				'description'       => 'Webhook URL to notify when this artifact is publicly viewed.',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'esc_url_raw',
				'auth_callback'     => static function ( bool $allowed, string $meta_key, int $post_id ): bool {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);
	}

	public function fire_webhook( \WP_Post $post ): void {
		if ( is_preview() || is_user_logged_in() ) {
			return;
		}

		$url = get_post_meta( $post->ID, self::META_KEY, true );
		if ( ! $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return;
		}

		$payload = array(
			'event'       => 'artifact_viewed',
			'artifact_id' => $post->ID,
			'title'       => get_the_title( $post ),
			'url'         => get_permalink( $post->ID ) ?: '',
			'timestamp'   => gmdate( 'c' ),
			'ip'          => $this->get_client_ip(),
			'user_agent'  => isset( $_SERVER['HTTP_USER_AGENT'] )
				? substr( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ), 0, 255 )
				: '',
		);

		$payload = (array) apply_filters( 'wmac_view_webhook_payload', $payload, $post );

		wp_remote_post(
			$url,
			array(
				'body'      => (string) wp_json_encode( $payload ),
				'headers'   => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'timeout'   => 0.1,
				'blocking'  => false,
				'sslverify' => (bool) apply_filters( 'wmac_view_webhook_sslverify', true ),
			)
		);
	}

	private function get_client_ip(): string {
		$headers = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );
		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = trim( (string) explode( ',', wp_unslash( (string) $_SERVER[ $header ] ) )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '';
	}
}
