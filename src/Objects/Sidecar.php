<?php

namespace Dapr\SwarmInjector\Objects;

class Sidecar {
	public function __construct( public Container|null $container ) {
	}

	public function constructFor( TrackedContainer $needsSidecar ): array {
		global $monitor_options;
		$labels  = $this->getAllLabels( $needsSidecar );
		$request = $this->getContainerRequestFromTemplate( $labels, $needsSidecar );

		return $request;
	}

	private function getAllLabels( TrackedContainer $needsSidecar ): array {
		global $monitor_options;

		return array_filter( $needsSidecar->container->getLabels(), fn( $key ) => str_starts_with( $key, $monitor_options->getLabelPrefix() ), ARRAY_FILTER_USE_KEY );
	}

	public function needsUpdate( TrackedContainer $target ): bool {
		global $monitor_options;
		$labels  = $this->getAllLabels( $target );
		$request = $this->getContainerRequestFromTemplate( $labels, $target );

		$current = $this->container->container;

		return ! ( $request['Image'] === $current['Image']
		           && ( $request['Env'] ?? [] ) === ( $current['Env'] ?? [] )
		           && implode( ' ', $request['Cmd'] ) === $current['Command'] );
	}

	private function getContainerRequestFromTemplate( array $labels, TrackedContainer $container ): array {
		global $monitor_options;

		$labelMap = $this->getLabelMap();
		if ( ! $this->validateLabelMap( $labelMap ) ) {
			throw new \RuntimeException( 'Failed to validate label mapping configuration!' );
		}
		$labelMap = $this->normalizeLabelMap( $labelMap );

		$getAllOfType = fn( string $type ) => array_filter( $labelMap, fn( $config ) => $config['type'] === $type );
		$render       = fn( $config ) => $this->renderValues( $config, $labels, $container );

		return [
			'Image'      => $monitor_options->getInjectImageName(),
			'Env'        => array_filter( $render( $getAllOfType( 'env' ) ) ),
			'Cmd'        => array_merge( [ $monitor_options->getCommandPrefix() ], array_merge( ...array_filter( $render( $getAllOfType( 'param' ) ), fn( $c ) => ! empty( $c[1] ) ) ) ),
			'HostConfig' => [
				'CpuShares'         => (int) $render( $getAllOfType( 'CPU.Request' ) )[0] ?? 0,
				'Memory'            => (int) $render( $getAllOfType( 'Memory.Limit' ) )[0] ?? 0,
				'CpuPeriod'         => (int) $render( $getAllOfType( 'CPU.Limit' ) )[0] ?? 0,
				'CpuQuota'          => (int) $render( $getAllOfType( 'CPU.Limit' ) )[0] ?? 0,
				'MemoryReservation' => (int) $render( $getAllOfType( 'Memory.Request' ) )[0] ?? 0,
				'RestartPolicy'     => [ 'Name' => $render( $getAllOfType( 'RestartPolicy' ) )[0][1] ?? 'always' ],
				'NetworkMode'       => 'container:' . $container->container->getId()
			],
			'Labels'     => [
				'swarm.injector/type' => 'sidecar:' . $monitor_options->getLabelPrefix(),
			],
		];
	}

	private function renderValues( array $configs, array $labels, TrackedContainer $container ): mixed {
		global $monitor_options;
		$values = [];
		foreach ( $configs as $config ) {
			if ( $config['kind'] === 'constant' ) {
				$values[] = [ $config['as'], $config['value'] ];
			}
			if ( $config['kind'] === 'label' ) {
				$expectedLabel = $monitor_options->getLabelPrefix() . '/' . $config['name'];
				$value         = $labels[ $expectedLabel ] ??
				                 ( $config['required'] && empty( $config['default'] )
					                 ? throw new \InvalidArgumentException( "Expected a label with $expectedLabel name, but there isn't one." )
					                 : $config['default'] );
				if ( $config['is_bool'] ?? false ) {
					$value = $value == 'true' ? $config['as'] : null;
				} else {
					$value = match ( $config['type'] ) {
						'env', 'RestartPolicy' => $value,
						'param' => [ $config['as'], $value ],
						'CPU.Request' => str_ends_with( $value, 'm' ) ? (int) ( intval( $value ) / 1000 * 1024 ) : $value * 1024,
						'CPU.Limit' => str_ends_with( $value, 'm' ) ? intval( $value ) * 100 : $value * 100000,
						'Memory.Request', 'Memory.Limit' => str_ends_with( $value, 'Mi' ) ? intval( $value ) * 1048576 : $value,
						default => $value
					};
				}
				foreach ( [ '%SERVICE_NAME%' => $container->container->getService() ?? $container->container->getId() ] as $placeholder => $replacement ) {
					$value = str_replace( $placeholder, $replacement, $value );
				}

				$values[] = $value;
			}
		}

		return $values;
	}

	private function validateLabelMap( array $labelMap ): bool {
		$valid = true;
		foreach ( $labelMap['labels'] as $label => $config ) {
			if ( ! isset( $config['type'] ) ) {
				echo "Missing configuration `type` on Label: `$label`\n";
				$valid = false;
			}
		}

		return $valid;
	}

	private function normalizeLabelMap( array $labelMap ): array {
		foreach ( $labelMap['labels'] as $label => &$config ) {
			$config['required'] ??= true;
			$config['default']  ??= null;
			$config['as']       ??= "-$label";
			$config['name']     = $label;
			$config['kind']     = 'label';
		}
		foreach ( $labelMap['constants'] as $constant => &$config ) {
			$config['as']   ??= "-$constant";
			$config['kind'] = 'constant';
		}

		return array_merge( $labelMap['labels'], $labelMap['constants'] );
	}

	public function getLabelMap() {
		global $monitor_options;

		if ( $monitor_options->getLabelMapConfig() && ! file_exists( '/' . $monitor_options->getLabelMapConfig() ) ) {
			\Phar::mount( '/' . $monitor_options->getLabelMapConfig(), '/' . $monitor_options->getLabelMapConfig() );
		}

		if ( $monitor_options->getLabelMapConfig() && file_exists( '/' . $monitor_options->getLabelMapConfig() ) ) {
			return json_decode( file_get_contents( '/' . $monitor_options->getLabelMapConfig() ), true );
		}

		return [
			'labels'    => [
				'app-port'               => [ 'type' => 'param', ],
				'config'                 => [ 'type' => 'param', 'required' => false ],
				'app-protocol'           => [ 'default' => 'http', 'type' => 'param' ],
				'app-id'                 => [ 'default' => '%SERVICE_NAME%', 'type' => 'param' ],
				'enable-profiling'       => [ 'type' => 'param', 'required' => false, 'is_bool' => true ],
				'log-level'              => [ 'default' => 'info', 'type' => 'param' ],
				'api-token-secret'       => [ 'required' => false, 'type' => 'secret' ],
				'app-token-secret'       => [ 'required' => false, 'type' => 'secret' ],
				'log-as-json'            => [
					'required' => 'false',
					'type'     => 'param',
					'is_bool'  => true,
					'default'  => 'false'
				],
				'app-max-concurrency'    => [ 'required' => false, 'type' => 'param' ],
				'enable-metrics'         => [ 'required' => false, 'type' => 'param', 'is_bool' => true ],
				'metrics-port'           => [ 'default' => '9090', 'type' => 'param' ],
				'env'                    => [ 'type' => 'env', 'required' => false ],
				'sidecar-cpu-limit'      => [ 'type' => 'CPU.Limit', 'required' => false, 'default' => '0' ],
				'sidecar-memory-limit'   => [ 'type' => 'Memory.Limit', 'required' => false, 'default' => '0' ],
				'sidecar-cpu-request'    => [ 'type' => 'CPU.Request', 'required' => false, 'default' => '0' ],
				'sidecar-memory-request' => [ 'type' => 'Memory.Request', 'required' => false, 'default' => '0' ],
				'http-max-request-size'  => [
					'type'     => 'param',
					'as'       => 'dapr-http-max-request-size',
					'required' => false
				],
				'app-ssl'                => [ 'type' => 'param', 'required' => false, 'is_bool' => true ],
			],
			'constants' => [
				'mode'                    => [ 'type' => 'param', 'value' => 'standalone', ],
				'dapr-http-port'          => [ 'type' => 'param', 'value' => '3500' ],
				'dapr-grpc-port'          => [ 'type' => 'param', 'value' => '50001' ],
				'dapr-internal-grpc-port' => [ 'type' => 'param', 'value' => '50002' ],
				'placement-host-address'  => [ 'type' => 'param', 'value' => 'placement:50005' ],
				'restart'                 => [ 'type' => 'RestartPolicy', 'value' => 'unless-stopped' ]
			]
		];
	}
}
