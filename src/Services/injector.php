<?php

namespace Dapr\SwarmInjector;

require_once __DIR__ . '/startup.php';

global $alive;
global $monitor_options;
global $client;
global $dockerClient;
global $eventHandler;

$services = $monitor_options->getCurrentConfigFile();
if ( ! file_exists( "/$services" ) ) {
	echo "Unable to locate service db from swarm configuration\nWaiting for update\n";
	while ( $alive ) {
		sleep( 3360 );
	}
}

function injectContainer( string $image, string $containerId, array $labels, string $labelPrefix ): void {

}

$services = json_decode( file_get_contents( $services ) );

$eventHandler->on( type: 'container', action: 'start', scope: 'local', do: function ( $event ) {
	var_dump( $event );
} );
$eventHandler->on( type: 'container', action: 'stop', scope: 'local', do: function ( $event ) {
	var_dump( $event );
} );

$eventHandler->start();

$containers = $dockerClient->getContainers();
foreach ( $containers as $container ) {
	$foundService = ( $container['Labels'] ?? [] )['com.docker.swarm.service.id'] ?? null;
	if ( $foundService === null ) {
		continue;
	}
	injectContainer( $monitor_options->getInjectImageName(), $container['Id'], $services[ $foundService ], $monitor_options->getLabelPrefix() );
}

while ( $alive ) {
	$eventHandler->update();
}
