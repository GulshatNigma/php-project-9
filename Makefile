PORT ?= 8000
start:
	  PHP_CLI_SERVER_WORKERS=5 php -S 0.0.0.0:$(PORT) -t public

lint:
	composer exec --verbose phpcs -- --standard=PSR12 public postgresqlphpconnect

test:
	composer exec --verbose phpunit tests

install:
	composer install

validate:
	composer validate
		
test-coverage:
	composer exec --verbose phpunit tests -- --coverage-clover ./build/logs/clover.xml
