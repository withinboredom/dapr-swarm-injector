<?php

namespace Dapr\SwarmInjector\Lib;

use Dapr\SwarmInjector\Exceptions\NotInSwarmMode;
use Dapr\SwarmInjector\Objects\Config;
use Dapr\SwarmInjector\Objects\Container;
use Dapr\SwarmInjector\Objects\Service;
use GuzzleHttp\Psr7\Request;
use Http\Client\Common\Exception\ServerErrorException;
use Http\Client\Socket\Client;
use Http\Client\Socket\Exception\InvalidRequestException;

function makeObject( &$part ) {
	$part = (object) $part;
}

/**
 * Class DockerClient
 *
 * A very minimal Docker client. This is not meant to be reused or exhaustive.
 *
 * @package Dapr\SwarmInjector\Lib
 */
class DockerClient {
	use UriBuilderTrait;

	public const API_VERSION = 'v1.41';

	public function __construct( private Client $client ) {
	}

	private function getFilters( array $filters ): array {
		if ( empty( $filters ) ) {
			return [];
		}

		return [
			'filters' => json_encode( $filters, JSON_UNESCAPED_SLASHES ),
		];
	}

	/**
	 * @param string $label
	 *
	 * @return Service[]
	 * @throws \Psr\Http\Client\ClientExceptionInterface
	 */
	public function getServices( string $label ): array {
		$request  = new Request(
			method: 'GET',
			uri: $this->createUri( '/services', $this->getFilters( [ 'label' => [ $label ] ] ) ),
			headers: [ 'Content-Type' => 'application/json' ] );
		$response = $this->client->sendRequest( $request );

		return match ( $response->getStatusCode() ) {
			503 => throw new NotInSwarmMode(),
			500 => throw new ServerErrorException( $response->getBody()->getContents(), $request, $response ),
			200 => array_map( fn( $service ) => new Service( $service ), $this->parseResponse( $response->getBody()->getContents() ) )
		};
	}

	public function getService( string $id ): Service {
		if ( empty( $id ) ) {
			throw new \LogicException( 'Tried to get details for an empty service' );
		}
		$request  = new Request( 'GET', $this->createUri( "/services/$id" ) );
		$response = $this->client->sendRequest( $request );

		return match ( $response->getStatusCode() ) {
			503 => throw new NotInSwarmMode(),
			500 => throw new ServerErrorException( $response->getBody()->getContents(), $request, $response ),
			404 => [],
			200 => new Service( $this->parseResponse( $response->getBody()->getContents() ) )
		};
	}

	public function updateService( Service $serviceToUpdate ): bool {
		$body = $serviceToUpdate->getJson();
		echo "Updating service {$serviceToUpdate->id} with version {$serviceToUpdate->version}\n";
		$request  = new Request( 'POST', $this->createUri( "/services/{$serviceToUpdate->id}/update", [ 'version' => $serviceToUpdate->version ] ), headers: [ 'Content-Length' => strlen( $body ) ], body: $body );
		$response = $this->client->sendRequest( $request );

		$responseBody = $response->getBody()->getContents();

		$parsedResponse = json_decode( $responseBody, true );
		foreach ( $parsedResponse['Warnings'] ?? [] as $warning ) {
			echo "Warning updating service: $warning\n";
		}

		return match ( $response->getStatusCode() ) {
			503 => throw new NotInSwarmMode(),
			500 => throw new ServerErrorException( $responseBody, $request, $response ),
			404 => false,
			400 => throw new InvalidRequestException( $responseBody, $request ),
			200 => true,
		};
	}

	private function parseResponse( string $response ): array {
		$response = explode( "\r\n", $response );
		foreach ( $response as $line ) {
			if ( str_starts_with( $line, '[' ) || str_starts_with( $line, '{' ) ) {
				return json_decode( $line, associative: true, flags: JSON_THROW_ON_ERROR );
			}
		}

		return [];
	}

	public function getLastConfig( string $label ): Config {
		$request      = new Request(
			method: 'GET',
			uri: $this->createUri( '/configs', $this->getFilters( [ 'label' => [ $label ] ] ) ),
			headers: [ 'Content-Type' => 'application/json' ]
		);
		$response     = $this->client->sendRequest( $request );
		$responseBody = $response->getBody()->getContents();

		return match ( $response->getStatusCode() ) {
			503 => throw new NotInSwarmMode(),
			500 => throw new ServerErrorException( $responseBody, $request, $response ),
			200 => new Config( $this->parseResponse( $responseBody ) ),
		};
	}

	public function newConfig( Config $config, string $label, string $value = 'true' ): Config {
		$config->version += 1;
		echo "Uploading new client version: {$config->version}\n";
		$body     = json_encode( [
			'Name'   => $config->getName(),
			'Labels' => [
				$label => $value
			],
			'Data'   => base64_encode( json_encode( [ 'services' => $config->services ] ) ),
		], JSON_UNESCAPED_SLASHES );
		$request  = new Request( 'POST', $this->createUri( '/configs/create' ), headers: [ 'Content-Length' => strlen( $body ) ], body: $body );
		$response = $this->client->sendRequest( $request );

		return match ( $response->getStatusCode() ) {
			503 => throw new NotInSwarmMode(),
			500 => throw new ServerErrorException( $response->getBody()->getContents(), $request, $response ),
			409 => throw new \RuntimeException( 'A later configuration already exists!' ),
			201 => $config->updateId( $this->parseResponse( $response->getBody()->getContents() )['ID'] )
		};
	}

	/**
	 * @param array $filters
	 *
	 * @return Container[]
	 * @throws \Psr\Http\Client\ClientExceptionInterface
	 */
	public function getContainers( array $filters = [] ): array {
		$request  = new Request( 'GET', $this->createUri( '/containers/json', $this->getFilters( $filters ) ) );
		$response = $this->client->sendRequest( $request );

		return match ( $response->getStatusCode() ) {
			500 => throw new ServerErrorException( $response->getBody()->getContents(), $request, $response ),
			400 => [],
			200 => array_map( fn( $c ) => new Container( $c ), $this->parseResponse( $response->getBody()->getContents() ) )
		};
	}

	public function createContainer( array $container, string $name ): string {
		$body         = json_encode( $container, JSON_UNESCAPED_SLASHES );
		$request      = new Request( 'POST', $this->createUri( '/containers/create', [ 'name' => $name ] ), headers: [
			'Content-Type'   => 'application/json',
			'Content-Length' => strlen( $body )
		], body: $body );
		$response     = $this->client->sendRequest( $request );
		$responseBody = $response->getBody()->getContents();

		return match ( $response->getStatusCode() ) {
			500 => throw new ServerErrorException( $response->getBody()->getContents(), $request, $response ),
			409 => throw new \InvalidArgumentException( $response->getBody()->getContents() ),
			404 => throw new \RuntimeException( $response->getBody()->getContents() ),
			400 => throw new \InvalidArgumentException( $response->getBody()->getContents() ),
			201 => json_decode( $responseBody, true )['Id']
		};
	}

	public function startContainer( string $id ): bool {
		$request  = new Request( 'POST', $this->createUri( "/containers/$id/start" ) );
		$response = $this->client->sendRequest( $request );

		$body = $response->getBody()->getContents();

		return match ( $response->getStatusCode() ) {
			500 => throw new ServerErrorException( $body, $request, $response ),
			404 => throw new \InvalidArgumentException( $body ),
			304 => false,
			204 => true,
		};
	}

	public function stopContainer( string $id ): bool {
		$request  = new Request( 'POST', $this->createUri( "/containers/$id/stop" ) );
		$response = $this->client->sendRequest( $request );

		$body = $response->getBody()->getContents();

		return match ( $response->getStatusCode() ) {
			500 => throw new ServerErrorException( $body, $request, $response ),
			404 => throw new \InvalidArgumentException( $body ),
			304 => false,
			204 => true,
		};
	}

	public function removeContainer( string $id, bool $volumes = false, bool $force = false ): bool {
		$request  = new Request( 'DELETE', $this->createUri( "/containers/$id", [
			'v'     => $volumes ? 'true' : 'false',
			'force' => $force ? 'true' : 'false'
		] ) );
		$response = $this->client->sendRequest( $request );

		$body = $response->getBody()->getContents();

		return match ( $response->getStatusCode() ) {
			500 => throw new ServerErrorException( $body, $request, $response ),
			404 => throw new \InvalidArgumentException( $body ),
			304 => false,
			204 => true,
		};
	}
}
