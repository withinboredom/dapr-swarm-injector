<?php

namespace Dapr\SwarmInjector\Objects;

class TrackedContainer {
	private Sidecar|null $sidecar = null;

	public function __construct( public Container $container ) {
	}

	public function addExistingSidecar( Sidecar|null $sidecar ): void {
		$this->sidecar = $sidecar;
	}

	public function hasSidecar(): bool {
		return $this->sidecar !== null;
	}
}
