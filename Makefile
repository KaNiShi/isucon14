.PHONY: deploy
deploy:
	git pull
	sudo systemctl restart isuride-php.service