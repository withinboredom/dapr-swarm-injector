.PHONY: build
build:
	docker-compose build

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

.PHONY: test
test: build
	docker-compose up || docker-compose down -v
