<?php

namespace Dapr\SwarmInjector\Lib;

use Dapr\SwarmInjector\Exceptions\NotInSwarmMode;
use Dapr\SwarmInjector\Objects\Config;
use Dapr\SwarmInjector\Objects\Container;
use GuzzleHttp\Psr7\Request;
use Http\Client\Common\Exception\ServerErrorException;
use Http\Client\Socket\Client;

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

	private function getFilters( array $filters ) {
		return [
			'filters' => json_encode( $filters, JSON_UNESCAPED_SLASHES ),
		];
	}

	public function getServices( string $label ): array {
		$request  = new Request(
			method: 'GET',
			uri: $this->createUri( '/services', $this->getFilters( [ 'label' => [ $label ] ] ) ),
			headers: [ 'Content-Type' => 'application/json' ] );
		$response = $this->client->sendRequest( $request );

		return match ( $response->getStatusCode() ) {
			503 => throw new NotInSwarmMode(),
			500 => throw new ServerErrorException( $response->getBody()->getContents(), $request, $response ),
			200 => json_decode( $response->getBody()->getContents(), true )
		};
	}

	public function getLastConfig( string $label ): Config {
		$request  = new Request(
			method: 'GET',
			uri: $this->createUri( '/configs', $this->getFilters( [ 'label' => [ $label ] ] ) ),
		);
		$response = $this->client->sendRequest( $request );

		return match ( $response->getStatusCode() ) {
			503 => throw new NotInSwarmMode(),
			500 => throw new ServerErrorException( $response->getBody()->getContents(), $request, $response ),
			200 => new Config( json_decode( $response->getBody()->getContents(), true ) ),
		};
	}

	public function newConfig( Config $config, string $label, string $value = 'true'): Config {
		$config->version += 1;
		$body     = json_encode( [
			'Name'   => $config->getName(),
			'Labels' => [
				$label => $value
			],
			'Data'   => base64_encode( json_encode( [ 'services' => $config->services ] ) ),
		], JSON_UNESCAPED_SLASHES );
		$request  = new Request( 'POST', $this->createUri( '/configs/create' ), body: $body, headers: [ 'Content-Length' => strlen( $body ) ] );
		$response = $this->client->sendRequest( $request );

		return match ( $response->getStatusCode() ) {
			503 => throw new NotInSwarmMode(),
			500 => throw new ServerErrorException( $response->getBody()->getContents(), $request, $response ),
			409 => throw new \RuntimeException( 'A later configuration already exists!' ),
			201 => $config->updateId(json_decode($response->getBody()->getContents())->ID)
		};
	}

	public function createService(string $name, array $labels, Container $container) {}
}
