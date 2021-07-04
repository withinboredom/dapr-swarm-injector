.PHONY: build

-include .env
-include /run/secrets/.env

CONTEXT = $(shell docker context show)
PLATFORMS ?= "linux/386,linux/amd64,linux/arm/v6,linux/arm64/v8,linux/mips64le,linux/ppc64le,linux/s390x"
TAG ?= dev

build: docker-compose.dist.yml docker-stack.dist.yml
	docker-compose -f docker-compose.dist.yml build

composer.lock: composer.json
	composer update

vendor/autoload.php: composer.lock
	composer install

tools/box:
	phive install humbug/box --force-accept-unsigned

monitor.phar: tools/box vendor/autoload.php
	tools/box compile -c monitor.box.json

injector.phar: tools/box vendor/autoload.php
	tools/box compile -c injector.box.json

.PHONY: phar
phar: monitor.phar injector.phar

docker-compose.dist.yml: tools/box docker-compose.yml injector.box.json .git/refs/heads/main
	tools/box process -c injector.box.json docker-compose.yml | php clean.php > docker-compose.dist.yml

docker-stack.dist.yml: tools/box docker-stack.yml injector.box.json .git/refs/heads/main
	tools/box process -c injector.box.json docker-stack.yml | php clean.php > docker-stack.dist.yml

.PHONY: test
test: build
	docker stack deploy test -c docker-stack.dist.yml
	docker service update test_swarm-monitor --force

publish:
	docker context use default
	docker build --secret id=.env,src=.env --target monitor --pull --tag withinboredom/dapr-swarm-monitor:$(TAG) .
	docker build --secret id=.env,src=.env --target injector --pull --tag withinboredom/dapr-swarm-injector:$(TAG) .
	docker push withinboredom/dapr-swarm-monitor:$(TAG)
	docker push withinboredom/dapr-swarm-injector:$(TAG)
	#docker buildx install
	#docker build --platform $(PLATFORMS) --secret id=.env,src=.env --target monitor --tag withinboredom/dapr-swarm-monitor:$(TAG) --push .
	#docker build --platform $(PLATFORMS) --secret id=.env,src=.env --target injector --tag withinboredom/dapr-swarm-injector:$(TAG) --push .
	#docker buildx uninstall
	docker context use $(CONTEXT)
