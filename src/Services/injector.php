<?php

namespace Dapr\SwarmInjector;

use Dapr\SwarmInjector\Objects\SidecarMap;

require_once __DIR__ . '/startup.php';

global $alive;
global $monitor_options;
global $dockerClient;
global $eventHandler;

echo "Waiting for environment configuration\n";
while ( $alive ) {
	sleep( 1 );
	if ( ! empty( $monitor_options->getCurrentConfigFile() ) ) {
		\Phar::mount( '/' . $monitor_options->getCurrentConfigFile(), '/' . $monitor_options->getCurrentConfigFile() );
		echo "Now using config: {$monitor_options->getCurrentConfigFile()}\n";
		echo file_get_contents( $monitor_options->getCurrentConfigFile() ) . "\n";
		break;
	}
}

$serviceLabels = json_decode( file_get_contents( $monitor_options->getCurrentConfigFile() ), true );

// check to see if we have a component image
$componentImage = $monitor_options->getComponentImage();
$mountPoint     = $monitor_options->getComponentPath();
if ( $componentImage !== null ) {
	// check to see if a volume already exists for it
	$volumeName = sha1( $componentImage );
	if ( ! $dockerClient->volumeExists( $volumeName ) ) {
		// create the volume and fill with data
		if ( $dockerClient->createVolume( $volumeName, [
			'from-image'   => $componentImage,
			'swarm.inject' => 'true'
		] ) ) {
			echo "Created new volume $volumeName for $componentImage\n";
			$containerId = $dockerClient->createContainer( [
				'Image'      => $componentImage,
				'HostConfig' => [
					'Binds' => [
						"$volumeName:$mountPoint:rw"
					]
				]
			], $volumeName );
			$dockerClient->removeContainer( $containerId, false );
			echo "Initialized volume $volumeName with component images from $mountPoint\n";
		}
	}
}

function doit() {
	global $dockerClient, $serviceLabels;
	$containers = $dockerClient->getContainers();
	$map        = new SidecarMap( $containers, $serviceLabels['services'] ?? [] );
	$map->reconcileSidecars( $dockerClient );
}

$eventHandler->start();

doit();

$eventHandler->on( type: 'container', scope: 'local', do: fn() => doit() );

while ( $alive ) {
	$eventHandler->update();
}
