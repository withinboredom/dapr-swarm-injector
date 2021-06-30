<?php

namespace Dapr\SwarmInjector\Build;

class Metadata {
	public static function generateMetadata(): array {
		$sha     = `git rev-parse --short HEAD`;
		$dirty   = `git diff-index --quiet HEAD -- || echo "-dirty"`;
		$version = "$sha$dirty";

		return [
			'build-version' => $version
		];
	}
}
