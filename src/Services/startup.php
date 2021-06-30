<?php

use Dapr\SwarmInjector\Lib\DockerClient;
use Dapr\SwarmInjector\Lib\EventHandler;
use Dapr\SwarmInjector\Lib\Options;
use Http\Client\Socket\Client;

echo "Starting Docker Swarm Injector Services\n";
echo "Version: @git@ built at @datetime@\n";

require_once __DIR__ . '/../../vendor/autoload.php';
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

global $client;
global $dockerClient;
global $eventHandler;
global $config;

$client       = new Client( $client_options );
$dockerClient = new DockerClient( $client );
$eventHandler = new EventHandler( $client_options );
