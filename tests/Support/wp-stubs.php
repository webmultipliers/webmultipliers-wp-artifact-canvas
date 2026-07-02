<?php
/**
 * Minimal stand-ins for WordPress core classes referenced by type hints in
 * src/. These are NOT full WordPress — Brain\Monkey mocks every WP function
 * the code under test calls. These classes exist only so PHP's type-checker
 * has something to instantiate/accept; they hold plain data.
 */

declare( strict_types=1 );

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID;
		public string $post_type       = '';
		public string $post_status     = 'publish';
		public string $post_title      = '';
		public string $post_content    = '';
		public string $post_password   = '';
		public string $post_author     = '1';
		public string $post_name       = '';
		public string $post_excerpt    = '';

		public function __construct( array $data = [] ) {
			foreach ( $data as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		/** @var array<string, array<int, string>> */
		private array $errors = [];
		/** @var array<string, mixed> */
		private array $error_data = [];

		public function __construct( string $code = '', string $message = '', $data = null ) {
			if ( $code !== '' ) {
				$this->errors[ $code ][] = $message;
				if ( $data !== null ) {
					$this->error_data[ $code ] = $data;
				}
			}
		}

		public function get_error_code(): string {
			$codes = array_keys( $this->errors );
			return $codes[0] ?? '';
		}

		public function get_error_message(): string {
			$code = $this->get_error_code();
			return $this->errors[ $code ][0] ?? '';
		}

		public function get_error_data( ?string $code = null ) {
			$code = $code ?? $this->get_error_code();
			return $this->error_data[ $code ] ?? null;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server {
		const CREATABLE = 'POST';
		const READABLE  = 'GET';
		const EDITABLE  = 'POST, PUT, PATCH';
		const DELETABLE = 'DELETE';
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		/** @var array<string, string> */
		private array $headers;
		private string $body;
		/** @var array<string, mixed> */
		private array $params;
		/** @var array<string, mixed>|null */
		private ?array $json_params;
		/** @var array<string, mixed> */
		private array $file_params;

		public function __construct(
			array $params = [],
			array $headers = [],
			string $body = '',
			?array $json_params = null,
			array $file_params = []
		) {
			$this->params      = $params;
			$this->headers     = array_change_key_case( $headers, CASE_LOWER );
			$this->body        = $body;
			$this->json_params = $json_params;
			$this->file_params = $file_params;
		}

		public function get_header( string $key ): ?string {
			return $this->headers[ strtolower( $key ) ] ?? null;
		}

		public function get_body(): string {
			return $this->body;
		}

		public function get_json_params(): ?array {
			return $this->json_params;
		}

		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function get_file_params(): array {
			return $this->file_params;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public $data;
		public int $status;

		public function __construct( $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
	}
}

if ( ! defined( 'COOKIEHASH' ) ) {
	define( 'COOKIEHASH', 'phpunittesthash' );
}

if ( ! defined( 'YEAR_IN_SECONDS' ) ) {
	define( 'YEAR_IN_SECONDS', 365 * 24 * 60 * 60 );
}
