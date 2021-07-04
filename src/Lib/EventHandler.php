<?php

namespace Dapr\SwarmInjector\Lib;

use GuzzleHttp\Psr7\Request;
use Http\Client\Socket\Client;

class EventHandler extends Client {
	use UriBuilderTrait;

	public const API_VERSION = 'v1.41';
	private $socket;
	private array $callbacks = [
	];

	public function __construct( private $config ) {
		parent::__construct( $config );
	}

	public function start() {
		$request      = new Request( 'GET', $this->createUri( '/events' ) );
		$remote       = $this->config['remote_socket'];
		$this->socket = $this->createSocket( $request, $remote, $this->config['ssl'] ?? false );
		$this->writeRequest( $this->socket, $request, 8096 );
		echo "Started listening to events\n";
	}

	public function on( string|false $type = false, string|false $action = false, string|false $scope = false, callable|null $do = null ) {
		$this->callbacks["$type|$action|$scope"][] = $do;
	}

	private function getAllPredicates( string|false $type, string|false $action, string|false $scope ) {
		return [
			[ $type, $action, $scope ],
			[ false, $action, $scope ],
			[ false, false, $scope ],
			[ $type, false, $scope ],
			[ $type, false, false ],
			[ false, $action, false ],
			[ $type, $action, false ],
			[ false, false, false ],
		];
	}

	public function update() {
		while ( false !== ( $line = fgets( $this->socket ) ) ) {
			if ( '' === rtrim( $line ) ) {
				break;
			}
			$line = trim( $line );
			if ( str_starts_with( $line, '{' ) ) {
				$event      = json_decode( $line, true );
				echo "$line\n";
				$predicates = $this->getAllPredicates( $event['Type'] ?? false, $event['Action'] ?? false, $event['scope'] ?? false );
				foreach ( $predicates as $predicate ) {
					$predicate   = implode( '|', $predicate );
					$didcallback = false;
					foreach ( $this->callbacks[ $predicate ] ?? [] as $callback ) {
						$callback( $event );
						$didcallback = true;
					}
					if ( $didcallback ) {
						echo "Handled event:\n$line\n";
					}
				}
			}
		}
	}
}
