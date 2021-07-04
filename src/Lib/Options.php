<?php

namespace Dapr\SwarmInjector\Lib;

class Options {
	public function getRemote(): string {
		return $this->getEnv( 'DOCKER_HOST', 'unix:///var/run/docker.sock' );
	}

	public function getEnv( string $key, string|null $default = null ): string|null {
		$env = getenv( $key );
		if ( $env === false ) {
			return $default;
		}
		if ( file_exists( '/run/secrets/' . $env ) ) {
			$data = file_get_contents( '/run/secrets/' . $env );

			return $data;
		}

		return $env;
	}

	public function getTLS(): string {
		return $this->getEnv( 'DOCKER_CERT', 'false' );
	}

	public function getCurrentConfigFile(): string {
		return $this->getEnv( 'CONFIG_NAME', '' );
	}

	public function getInjectImageName(): string {
		return $this->getEnv( 'INJECT_IMAGE', 'daprio/daprd:edge' );
	}

	public function getLabelPrefix(): string {
		return $this->getEnv( 'LABEL_PREFIX', 'dapr.io' );
	}

	public function getCommandPrefix(): string {
		return $this->getEnv( 'COMMAND_PREFIX', './daprd' );
	}

	public function getLabelMapConfig(): string|null {
		return $this->getEnv( 'LABEL_MAP_CONFIG' );
	}

	public function getInjectorImage(): string|null {
		return str_replace( '@', '-', $this->getEnv( 'INJECTOR_IMAGE', 'withinboredom/dapr-swarm-injector:@git@' ) );
	}

	public function getAlwaysUpdateInjector(): bool {
		return $this->getEnv( 'ALWAYS_UPDATE', 'false' ) === 'true';
	}

	public function getComponentImage(): string|null {
		return $this->getEnv( 'COMPONENT_IMAGE', null );
	}

	public function getComponentPath(): string|null {
		return $this->getEnv( 'COMPONENT_PATH', '/components' );
	}

	public function getAuth(): string|null {
		return $this->getEnv( 'DOCKER_AUTH', null );
	}
}
