<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

class Plugin {

	private static ?self $instance = null;

	private PostType    $post_type;
	private BlockType   $block_type;
	private Renderer    $renderer;
	private Security    $security;

	private function __construct() {}

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init(): void {
		$this->post_type  = new PostType();
		$this->block_type = new BlockType();
		$this->renderer   = new Renderer();
		$this->security   = new Security();

		$this->post_type->register_hooks();
		$this->block_type->register_hooks();
		$this->renderer->register_hooks();
		$this->security->register_hooks();
	}
}
