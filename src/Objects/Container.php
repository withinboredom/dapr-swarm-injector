<?php

namespace Dapr\SwarmInjector\Objects;

class Container {
	public function __construct( public array $container, public array $serviceLabels = [] ) {
	}

	public function updateServiceLabels( array|null $labels ) {
		$this->serviceLabels = $labels ?? [];
	}

	public function getService(): string|null {
		return $this->container['Labels']['com.docker.swarm.service.id'] ?? null;
	}

	public function getLabels(): array {
		return array_merge( $this->serviceLabels, $this->container['Labels'] );
	}

	public function getLabel( string $key ): string|null {
		var_dump($this->getLabels());
		return $this->getLabels()[ $key ] ?? null;
	}

	public function getId(): string {
		return $this->container['Id'];
	}

	public function getNetworkTargetContainer(): string|null {
		$mode = ( $this->container['HostConfig'] ?? [] )['NetworkMode'] ?? '';
		if ( str_starts_with( $mode, 'container:' ) ) {
			return substr( $mode, strlen( 'container:' ) );
		}

		return null;
	}
}
