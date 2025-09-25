install: generate-env stop build start install-vendors

generate-env:
	@rm -f .env
	@echo "UID=`id -u`" >> .env

stop:
	docker compose down --remove-orphans

build:
	docker compose build

start: stop
	docker compose up -d

install-vendors:
	docker compose run --rm php composer install

fix:
	docker compose run --rm php vendor/bin/php-cs-fixer fix --allow-risky=yes
	docker compose run --rm php vendor/bin/phpstan analyse --no-progress -vvv --memory-limit=1024M
