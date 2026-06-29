<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

class Plugin {

	private static ?self $instance = null;

	// Core
	private PostType      $post_type;
	private BlockType     $block_type;
	private Renderer      $renderer;
	private Security      $security;
	private OEmbed        $oembed;
	private Revisions     $revisions;
	private SeoMeta       $seo_meta;
	private ArtifactFile  $artifact_file;
	private AdminColumns  $admin_columns;
	private Ingestion     $ingestion;
	private ArtifactMeta  $artifact_meta;
	private ArtifactAlias $artifact_alias;
	private Settings      $settings;
	private AssetMapper   $asset_mapper;
	private MergeTags     $merge_tags;

	// Phase A — DAM foundation
	private ClientTaxonomy  $client_taxonomy;
	private LifecycleStatus $lifecycle_status;
	private OwnershipMeta   $ownership_meta;

	// Phase B — Client engagement
	private LinkGovernance $link_governance;
	private ClientTracking $client_tracking;
	private ViewWebhook    $view_webhook;

	// Phase C — Landing page engine
	private PageUsurpation $page_usurpation;

	// Phase D — Git-driven ingestion
	private GitWebhook       $git_webhook;
	private PackageValidator $package_validator; // stateless; instantiated by GitWebhook

	// Phase E — Multi-format engine
	private PdfRenderer $pdf_renderer;

	private function __construct() {}

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init(): void {
		// --- Core ---
		$this->post_type      = new PostType();
		$this->block_type     = new BlockType();
		$this->renderer       = new Renderer();
		$this->security       = new Security();
		$this->oembed         = new OEmbed();
		$this->revisions      = new Revisions();
		$this->seo_meta       = new SeoMeta();
		$this->artifact_file  = new ArtifactFile();
		$this->admin_columns  = new AdminColumns();
		$this->ingestion      = new Ingestion();
		$this->artifact_meta  = new ArtifactMeta();
		$this->artifact_alias = new ArtifactAlias();
		$this->settings       = new Settings();
		$this->asset_mapper   = new AssetMapper();
		$this->merge_tags     = new MergeTags();

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
		$this->artifact_alias->register_hooks();
		$this->settings->register_hooks();
		$this->asset_mapper->register_hooks();
		$this->merge_tags->register_hooks();

		// --- Phase A — DAM foundation ---
		$this->client_taxonomy  = new ClientTaxonomy();
		$this->lifecycle_status = new LifecycleStatus();
		$this->ownership_meta   = new OwnershipMeta();

		$this->client_taxonomy->register_hooks();
		$this->lifecycle_status->register_hooks();
		$this->ownership_meta->register_hooks();

		// --- Phase B — Client engagement ---
		$this->link_governance = new LinkGovernance();
		$this->client_tracking = new ClientTracking();
		$this->view_webhook    = new ViewWebhook();

		$this->link_governance->register_hooks();
		$this->client_tracking->register_hooks();
		$this->view_webhook->register_hooks();

		// --- Phase C — Landing page engine ---
		$this->page_usurpation = new PageUsurpation();
		$this->page_usurpation->register_hooks();

		// --- Phase D — Git-driven ingestion ---
		$this->git_webhook = new GitWebhook();
		$this->git_webhook->register_hooks();

		// --- Phase E — Multi-format engine ---
		$this->pdf_renderer = new PdfRenderer();
		$this->pdf_renderer->register_hooks();
	}
}
