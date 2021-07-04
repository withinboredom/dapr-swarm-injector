<?php

namespace Dapr\SwarmInjector\Objects;

use Dapr\SwarmInjector\Lib\DockerClient;

class SidecarMap {
	private static array $updatedServices = [];
	/**
	 * @var Sidecar[]
	 */
	public array $sidecars = [];
	/**
	 * @var TrackedContainer[]
	 */
	public array $containers = [];

	/**
	 * SidecarMap constructor.
	 *
	 * @param Container[] $containers
	 */
	public function __construct( array $containers, array $serviceLabels ) {
		global $monitor_options;
		foreach ( $containers as $container ) {
			$container->updateServiceLabels( $serviceLabels[ $container->getService() ] ?? null );
			if ( $container->getLabel( $monitor_options->getLabelPrefix() . '/enabled' ) === 'true' ) {
				$this->containers[ $container->getId() ] = new TrackedContainer( $container );
			} else if ( $container->getLabel( 'swarm.injector/type' ) === 'sidecar:' . $monitor_options->getLabelPrefix() ) {
				$target = $container->getNetworkTargetContainer();
				if ( ( $this->sidecars[ $target ] ?? null ) !== null ) {
					throw new \RuntimeException( 'There is already is more than one injected sidecar!' );
				}
				$this->sidecars[ $target ] = new Sidecar( $container );
			} else {
				echo "Not considering {$container->getId()} due to missing {$monitor_options->getLabelPrefix()}/enabled label\n";
			}
		}
		foreach ( $this->containers as $container ) {
			$container->addExistingSidecar( $this->sidecars[ $container->container->getId() ] ?? null );
		}
	}

	public function reconcileSidecars( DockerClient $dockerClient ): void {
		foreach ( $this->containers as $containerId => $container ) {
			echo "Checking container $containerId\n";
			if ( ! $container->hasSidecar() ) {
				if ( in_array( $container->container->getService(), self::$updatedServices ) ) {
					self::$updatedServices = array_filter( self::$updatedServices, fn( $id ) => $id !== $container->container->getService() );
				}
				echo "Starting new sidecar for {$container->container->getId()}\n";
				$sidecar      = new Sidecar( null );
				$newContainer = $sidecar->constructFor( $container );
				$id           = $dockerClient->createContainer( $newContainer, uniqid() );
				if ( ! $dockerClient->startContainer( $id ) ) {
					echo 'Failed to start container for ' . $container->container->getId() . "!\n";
					continue;
				}
			} else {
				echo "Skipping container $containerId because it already has a sidecar\n";
			}
		}
		foreach ( $this->sidecars as $containerId => $sidecar ) {
			echo "Checking sidecar {$sidecar->container->getId()} for $containerId\n";
			if ( ! isset( $this->containers[ $containerId ] ) ) {
				echo "Removing no longer required sidecar for $containerId\n";
				$dockerClient->removeContainer( $sidecar->container->getId(), force: true );
				continue;
			}
			if ( $sidecar->needsUpdate( $this->containers[ $containerId ] ) ) {
				if ( in_array( $this->containers[ $containerId ]->container->getService(), self::$updatedServices ) ) {
					continue;
				}
				echo "Detected a service change that requires a container restart. Please update service with --force!\n";
				continue;
			}
		}
	}
}
