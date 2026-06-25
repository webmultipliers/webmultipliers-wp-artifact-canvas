<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

class Activation {

	public static function activate(): void {
		( new PostType() )->register();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}
