.PHONY: build
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
	docker build --platform linux/x64,linux/arm64,linux/amd64,darwin/universal --target monitor --tag withinboredom/dapr-swarm-monitor:dev --push .
	docker build --platform linux/x64,linux/arm64,linux/amd64,darwin/universal --target injector --tag withinboredom/dapr-swarm-injector:dev --push .
