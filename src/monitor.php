<?php

namespace Dapr\SwarmInjector;

require_once __DIR__ . '/../vendor/autoload.php';

use Dapr\SwarmInjector\Lib\DockerClient;
use Dapr\SwarmInjector\Lib\EventHandler;
use Dapr\SwarmInjector\Lib\Options;
use Dapr\SwarmInjector\Objects\Config;
use Http\Client\Socket\Client;

$alive = true;

pcntl_signal( SIGINT, function () use ( &$alive ) {
	$alive = false;
} );

$monitor_options = new Options();

$client_options = [
	'remote_socket' => $monitor_options->getRemote(),
];

if ( $tls = $monitor_options->getTLS() ) {
	$client_options['ssl']                    = true;
	$client_options['stream_context_options'] = [
		'ssl' => [
			'local_cert' => $tls
		]
	];
}

$client       = new Client( $client_options );
$dockerClient = new DockerClient( $client );
$eventHandler = new EventHandler( $client_options );

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
