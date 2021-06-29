<?php

namespace Dapr\SwarmInjector\Objects;

class Config {
	public int $version = 0;
	public array $services = [];
	public string $id = '';

	public function getName(): string {
		return 'dapr-inject-v' . $this->version . '.json';
	}

	public function __construct( array $configs = [] ) {
		if ( isset( $configs['Spec'] ) ) {
			$configs = [ $configs ];
		}
		foreach ( $configs as $config ) {
			$version = explode( '-v', $config['Spec']['Name'] )[1] ?? '1.json';
			$version = explode( '.', $version )[0] ?? '1';
			if ( $version > $this->version ) {
				$this->version  = $version;
				$data           = json_decode( base64_decode( $config['Spec']['Data'] ?? 'W10=' /* [] */ ), true );
				$this->services = $data['services'] ?? [];
				$this->id       = $config['ID'];
			}
		}
	}

	public function updateId(string $newId): self {
		$this->id = $newId;

		return $this;
	}
}
