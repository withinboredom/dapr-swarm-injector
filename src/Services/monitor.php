<?php

namespace Dapr\SwarmInjector;

use Dapr\SwarmInjector\Objects\Config;

require_once __DIR__ . '/startup.php';

global $eventHandler;
global $dockerClient;
global $alive;

// start listening to new events before we process our initial service list
$eventHandler->start();
//$eventHandler->on(type: 'container', action: 'create', scope: 'local');

$services = $dockerClient->getServices( 'dapr.io/enabled' );
$config   = $dockerClient->getLastConfig( 'dapr.inject' );

$doUpdateConfig = false;
foreach ( $services as $serviceDescription ) {
	$id     = $serviceDescription['ID'];
	$labels = $serviceDescription['Spec']['Labels'];
	var_dump( $labels );
	if ( isset( $config->services[ $id ] ) && $config->services[ $id ] === $labels ) {
		continue;
	}
	$config->services[ $id ] = $labels;
	$doUpdateConfig          = true;
}

$services = $dockerClient->getServices( 'dapr.inject' );

if ( $doUpdateConfig || empty( $services ) ) {
	$config = $dockerClient->newConfig( $config, 'dapr.inject' );
	if ( empty( $services ) ) {
		createInjectors( $config );
	} else {
		updateInjectors( $config );
	}
}

function updateInjectors( Config $config ) {

}

function createInjectors( Config $config ) {

}

while ( $alive ) {
	$eventHandler->update();

	usleep( 500000 );
}
