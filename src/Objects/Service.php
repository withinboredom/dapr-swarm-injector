<?php

namespace Dapr\SwarmInjector\Objects;

use function Dapr\SwarmInjector\Lib\makeObject;

class Service {
	public string $id;
	public int $version;

	public function __construct( public array $service ) {
		if ( ! isset( $this->service['ID'] ) ) {
			throw new \LogicException( 'Expect service from inspection!' );
		}
		$this->id      = $this->service['ID'];
		$this->version = $this->service['Version']['Index'];
		$this->service = $this->service['Spec'];
	}

	public function hasConfig( Config $config ): bool {
		if ( empty( $this->service['TaskTemplate']['ContainerSpec']['Configs'] ) || ! in_array( $config->id, array_map( fn( $conf ) => $conf['ConfigID'], $this->service['TaskTemplate']['ContainerSpec']['Configs'] ) ) ) {
			return false;
		}

		return true;
	}

	public function setConfig( Config $config ): void {
		$this->service['TaskTemplate']['ContainerSpec']['Configs'] = [
			[
				'ConfigId'   => $config->id,
				'ConfigName' => $config->getName(),
				'File'       => [
					'Name' => '/' . $config->getName(),
					'UID'  => '0',
					'GID'  => '0',
					'Mode' => 444,
				],
			],
		];
	}

	public function updateEnvironment( string $key, string|null $value ): void {
		$items = [];
		foreach ( $this->service['TaskTemplate']['ContainerSpec']['Env'] ?? [] as $item ) {
			$item              = explode( '=', $item, 2 );
			$items[ $item[0] ] = $item[1];
		}
		if ( $value === null ) {
			unset( $items[ $key ] );
		} else {
			$items[ $key ] = $value;
		}
		$this->service['TaskTemplate']['ContainerSpec']['Env'] = [];
		foreach ( $items as $key => $value ) {
			$this->service['TaskTemplate']['ContainerSpec']['Env'][] = "$key=$value";
		}
	}

	public function updateImage( string $image ): void {
		$this->service['TaskTemplate']['ContainerSpec']['Image'] = $image;
	}

	public function getJson(): string {
		makeObject( $this->service['TaskTemplate']['Resources'] );
		makeObject( $this->service['TaskTemplate']['Placement'] );
		makeObject( $this->service['Mode']['Global'] );

		return json_encode( $this->service, JSON_UNESCAPED_SLASHES );
	}

	public function getImage(): string {
		return $this->service['TaskTemplate']['ContainerSpec']['Image'];
	}
}
