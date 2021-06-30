<?php

namespace Dapr\SwarmInjector\Lib;

class Options {
	public function getEnv( string $key, string|null $default = null ): string {
		$env = getenv( $key );
		if ( $env === false ) {
			return $default;
		}
		if ( file_exists( '/run/secrets/' . $env ) ) {
			$data = file_get_contents( '/run/secrets/' . $env );

			return $data;
		}

		return $default;
	}

	public function getRemote(): string {
		return $this->getEnv( 'DOCKER_HOST', 'unix:///var/run/docker.sock' );
	}

	public function getTLS(): string {
		return $this->getEnv( 'DOCKER_CERT', 'false' );
	}

	public function getCurrentConfigFile(): string {
		return $this->getEnv( 'CONFIG_NAME', 'no-config' );
	}

	public function getInjectImageName(): string {
		return $this->getEnv( 'INJECT_IMAGE', 'dapr/daprd:v1.2.2' );
	}

	public function getLabelPrefix(): string {
		return $this->getEnv( 'LABEL_PREFIX', 'dapr.io' );
	}
}
