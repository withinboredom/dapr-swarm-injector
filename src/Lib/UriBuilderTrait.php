<?php

namespace Dapr\SwarmInjector\Lib;

use GuzzleHttp\Psr7\Query;

trait UriBuilderTrait {
	private function createUri( string $path = '/', array $query = [] ): string {
		$query = Query::build( $query, false );

		return 'http://localhost/' . self::API_VERSION . $path . ( $query ? '?' . $query : $query );
	}
}
