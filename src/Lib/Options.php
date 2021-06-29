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
		return $this->getEnv( 'DOCKER_CERT', false );
	}
}
