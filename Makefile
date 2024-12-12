.PHONY: deploy
deploy:
	git pull
	cd php && php composer.phar install --no-dev -o --apcu-autoloader
	sudo systemctl restart isuride-php.service
