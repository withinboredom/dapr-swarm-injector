<?php

namespace Dapr\SwarmInjector;

use Dapr\SwarmInjector\Objects\Config;
use Dapr\SwarmInjector\Objects\Service;

require_once __DIR__ . '/startup.php';

global $eventHandler;
global $dockerClient;
global $monitor_options;

// start listening to new events before we process our initial service list
$eventHandler->start();

// get all services with the prefix/enabled label
$services = $dockerClient->getServices( $monitor_options->getLabelPrefix() . '/enabled' );

// get the latest config with the label `swarm.inject`
$config = $dockerClient->getLastConfig( 'swarm.inject' );

if ( empty( $config->id ) ) {
	$config = $dockerClient->newConfig( $config, 'swarm.inject' );
}

$doUpdateConfig = false;
foreach ( $services as $serviceDescription ) {
	$id = $serviceDescription->id;
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

if ( ! $injectorService->hasConfig( $config ) ) {
	updateInjectors( $config, $injectorService );
} else {
	echo "Not updating injector because it is already using the latest config\n";
}

if ( $doUpdateConfig ) {
	$config = $dockerClient->newConfig( $config, 'swarm.inject' );
	updateInjectors( $config, $injectorService );
} else if ( $monitor_options->getInjectorImage() !== $injectorService->getImage() ) {
	updateInjectors( $config, $injectorService );
} else if ( $monitor_options->getAlwaysUpdateInjector() ) {
	echo "Force updating injector!\n";
	updateInjectors( $config, $injectorService, force: true );
}

/**
 * Update the discovered services with a new configuration
 *
 * @param Config $config
 * @param string $injectorService
 */
function updateInjectors( Config $config, Service|null $injectorService, bool $force = false ): void {
	global $dockerClient, $monitor_options;
	if ( empty( $injectorService ) ) {
		echo "Unable to update injector service with new configuration\nPlease deploy the stack to Docker Swarm.\n";
		exit( 1 );
	}
	echo "Updating injectors with latest config: v{$config->version}\n";
	$existingService = $dockerClient->getService( $injectorService->id );

	$existingService->setConfig( $config );
	$existingService->updateEnvironment( 'INJECT_IMAGE', $monitor_options->getInjectImageName() );
	$existingService->updateEnvironment( 'LABEL_PREFIX', $monitor_options->getLabelPrefix() );
	$existingService->updateEnvironment( 'CONFIG_NAME', $config->getName() );
	$existingService->updateEnvironment( 'COMMAND_PREFIX', $monitor_options->getCommandPrefix() );
	$existingService->updateEnvironment( 'COMPONENT_IMAGE', $monitor_options->getComponentImage() );
	$existingService->updateEnvironment( 'COMPONENT_PATH', $monitor_options->getComponentPath() );
	$existingService->updateImage( $monitor_options->getInjectorImage() );

	if ( $force ) {
		$existingService->service['TaskTemplate']['ForceUpdate'] ++;
	}

	if ( $monitor_options->getLabelMapConfig() ) {
		$existingService->updateEnvironment( 'LABEL_MAP_CONFIG', $monitor_options->getLabelMapConfig() );
	}

	if ( ! $dockerClient->updateService( $existingService ) ) {
		echo "Failed to update injector!\n";
	}
}

function addServiceToConfig( Config $config, string $serviceId ): bool {
	global $dockerClient, $monitor_options;
	$service = $dockerClient->getService( $serviceId );
	if ( empty( $service ) ) {
		echo "Skipping $serviceId because it appears to have gone missing\n";
	}
	$id     = $service->id;
	$labels = $service->service['Labels'];

	$isDifferent         = ( $config->services[ $id ] ?? [] ) !== $labels;
	$isNowEnabled        = $labels[ $monitor_options->getLabelPrefix() . '/enabled' ] ?? 'false' === 'true';
	$isPreviouslyEnabled = ( $config->services[ $id ] ?? [] )[ $monitor_options->getLabelPrefix() . '/enabled' ] ?? 'false' === 'true';

	$reason = $isDifferent ? 'updated config' : ( $isNowEnabled ? 'enabled' : ( $isPreviouslyEnabled ? 'prev-enabled' : 'not-enabled' ) );

	echo "Reason for $serviceId being tracked: $reason\n";

	if ( ( ! $isNowEnabled ) && $isDifferent ) {
		return removeServiceFromConfig( $config, $serviceId );
	}

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
