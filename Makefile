APP_NAME := isuride
BUILD_DIR := /home/isucon/webapp/go

.PHONY: php-deploy
php-deploy:
	git pull
	sudo systemctl restart isuride-php.service

.PHONY: go-deploy
go-deploy:
	git pull
	cd $(BUILD_DIR); go build -o $(APP_NAME)
	sudo systemctl restart isuride-go.service
	sudo systemctl restart nginx.service
