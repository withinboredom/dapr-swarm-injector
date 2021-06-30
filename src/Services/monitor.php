<?php

namespace Dapr\SwarmInjector;

use Dapr\SwarmInjector\Objects\Config;

require_once __DIR__ . '/startup.php';

global $eventHandler;
global $dockerClient;
global $monitor_options;

// start listening to new events before we process our initial service list
$eventHandler->start();

$services = $dockerClient->getServices( $monitor_options->getLabelPrefix() . '/enabled' );
$config   = $dockerClient->getLastConfig( 'swarm.inject' );

$doUpdateConfig = false;
foreach ( $services as $serviceDescription ) {
	$id = $serviceDescription['ID'];
	echo "Detected $id as service\n";
	if ( addServiceToConfig( $config, $id ) ) {
		$doUpdateConfig = true;
	}
}

$injectorService = ( $dockerClient->getServices( 'swarm.inject' )[0] ?? [] ) ?? null;

if ( $injectorService === null ) {
	echo 'Injector service does not exist!\n';
	exit( 1 );
}

if ( empty( $injectorService['Spec']['TaskTemplate']['ContainerSpec']['Configs'] ) || ! in_array( $config->id, array_map( fn( $conf ) => $conf['ConfigID'], $injectorService['Spec']['TaskTemplate']['ContainerSpec']['Configs'] ) ) ) {
	updateInjectors( $config, $injectorService['ID'] );
} else {
	echo "Not updating injector because it is already using the latest config\n";
}

$injectorService = $injectorService['ID'];

if ( $doUpdateConfig ) {
	$config = $dockerClient->newConfig( $config, 'swarm.inject' );
	updateInjectors( $config, $injectorService );
}

/**
 * Update the discovered services with a new configuration
 *
 * @param Config $config
 * @param string $injectorService
 */
function updateInjectors( Config $config, string|null $injectorService ): void {
	global $dockerClient;
	if ( empty( $injectorService ) ) {
		echo "Unable to update injector service with new configuration\nPlease deploy the stack to Docker Swarm.\n";
		exit( 1 );
	}
	echo "Updating injectors with latest config: v{$config->version}\n";
	$existingService = $dockerClient->getService( $injectorService );

	$existingService['Spec']['TaskTemplate']['ContainerSpec']['Configs'] = [
		[
			'ConfigID'   => $config->id,
			'ConfigName' => $config->getName(),
			'File'       => [ 'Name' => '/' . $config->getName(), 'UID' => '0', 'GID' => '0', 'Mode' => 444 ]
		]
	];
	if ( ! $dockerClient->updateService( $existingService['Spec'], $existingService['ID'], $existingService['Version']['Index'] ) ) {
		echo "Failed to update injector!\n";

		return;
	}
}

function addServiceToConfig( Config $config, string $serviceId ): bool {
	global $dockerClient, $monitor_options;
	$service = $dockerClient->getService( $serviceId );
	if ( empty( $service ) ) {
		echo "Skipping $serviceId because it appears to have gone missing\n";
	}
	$id     = $service['ID'];
	$labels = $service['Spec']['Labels'];

	$isDifferent         = ( $config->services[ $id ] ?? [] ) !== $labels;
	$isNowEnabled        = $labels[ $monitor_options->getLabelPrefix() . '/enabled' ] ?? 'false' === 'true';
	$isPreviouslyEnabled = ( $config->services[ $id ] ?? [] )[ $monitor_options->getLabelPrefix() . '/enabled' ] ?? 'false' === 'true';

	$reason = $isDifferent ? 'updated config' : ( $isNowEnabled ? 'enabled' : ( $isPreviouslyEnabled ? 'prev-enabled' : 'not-enabled' ) );

	if ( ! $isNowEnabled ) {
		return removeServiceFromConfig( $config, $serviceId );
	}

	echo "Reason for $serviceId being tracked: $reason\n";

	if ( $isDifferent && ( $isNowEnabled || $isPreviouslyEnabled ) ) {
		echo "Updating/inserting $serviceId into config\n";

		$config->services[ $id ] = $labels;

		return true;
	}

	return false;
}

function removeServiceFromConfig( Config $config, string $serviceId ): bool {
	$existed = isset( $config->services[ $serviceId ] );
	if ( $existed ) {
		echo "Deleting $serviceId from config\n";
	}
	unset( $config->services[ $serviceId ] );

	return $existed;
}

$updateHandler = function ( $event ) use ( &$config, $injectorService, $dockerClient ) {
	$serviceId = $event['Actor']['ID'];
	if ( addServiceToConfig( $config, $serviceId ) ) {
		$config = $dockerClient->newConfig( $config, 'swarm.inject' );
		updateInjectors( $config, $injectorService );
	}
};

$eventHandler->on( type: 'service', action: 'update', scope: 'swarm', do: $updateHandler );

$eventHandler->on( type: 'service', action: 'create', scope: 'swarm', do: $updateHandler );

$eventHandler->on( type: 'service', action: 'remove', scope: 'swarm', do: function ( $event ) use ( &$config, $injectorService, $dockerClient ) {
	$serviceId = $event['Actor']['ID'];
	if ( removeServiceFromConfig( $config, $serviceId ) ) {
		$config = $dockerClient->newConfig( $config, 'swarm.inject' );
		updateInjectors( $config, $injectorService );
	}
} );


global $alive;
while ( $alive ) {
	$eventHandler->update();
	sleep( 1 );
}
