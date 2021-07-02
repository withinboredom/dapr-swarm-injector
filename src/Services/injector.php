<?php

namespace Dapr\SwarmInjector;

use Dapr\SwarmInjector\Objects\SidecarMap;

require_once __DIR__ . '/startup.php';

global $alive;
global $monitor_options;
global $dockerClient;
global $eventHandler;

echo "Waiting for environment configuration\n";
$serviceLabels = [];
while ( $alive ) {
	sleep( 1 );
	if ( ! empty( $monitor_options->getCurrentConfigFile() ) ) {
		\Phar::mount( '/' . $monitor_options->getCurrentConfigFile(), '/' . $monitor_options->getCurrentConfigFile() );
		$serviceLabels = json_decode( file_get_contents( '/' . $monitor_options->getCurrentConfigFile() ), true );
		echo "Now using config: {$monitor_options->getCurrentConfigFile()}\n";
		break;
	}
}

function doit() {
	global $dockerClient, $serviceLabels;
	$containers = $dockerClient->getContainers();
	$map        = new SidecarMap( $containers, $serviceLabels['services'] );
	$map->reconcileSidecars( $dockerClient );
}

$eventHandler->start();

doit();

$eventHandler->on( type: 'container', action: 'start', scope: 'local', do: fn() => doit() );
$eventHandler->on( type: 'container', action: 'stop', scope: 'local', do: fn() => doit() );
$eventHandler->on( type: 'container', action: 'remove', scope: 'local', do: fn() => doit() );

while ( $alive ) {
	$eventHandler->update();
}
