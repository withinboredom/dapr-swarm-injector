<?php

namespace Dapr\SwarmInjector\Objects;

class Container {
	public function __construct(public string $image, public array $labels, public array $mounts) {}
}
