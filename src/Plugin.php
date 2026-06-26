<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

class Plugin {

	private static ?self $instance = null;

	private PostType     $post_type;
	private BlockType    $block_type;
	private Renderer     $renderer;
	private Security     $security;
	private OEmbed       $oembed;
	private Revisions    $revisions;
	private SeoMeta      $seo_meta;
	private ArtifactFile $artifact_file;
	private AdminColumns $admin_columns;
	private Ingestion    $ingestion;
	private ArtifactMeta $artifact_meta;
	private Settings     $settings;

	private function __construct() {}

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init(): void {
		$this->post_type     = new PostType();
		$this->block_type    = new BlockType();
		$this->renderer      = new Renderer();
		$this->security      = new Security();
		$this->oembed        = new OEmbed();
		$this->revisions     = new Revisions();
		$this->seo_meta      = new SeoMeta();
		$this->artifact_file = new ArtifactFile();
		$this->admin_columns = new AdminColumns();
		$this->ingestion     = new Ingestion();
		$this->artifact_meta = new ArtifactMeta();
		$this->settings      = new Settings();

		$this->post_type->register_hooks();
		$this->block_type->register_hooks();
		$this->renderer->register_hooks();
		$this->security->register_hooks();
		$this->oembed->register_hooks();
		$this->revisions->register_hooks();
		$this->seo_meta->register_hooks();
		$this->artifact_file->register_hooks();
		$this->admin_columns->register_hooks();
		$this->ingestion->register_hooks();
		$this->artifact_meta->register_hooks();
		$this->settings->register_hooks();
	}
}
