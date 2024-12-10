.PHONY: deploy matcher compose
deploy:
	git pull
	sudo systemctl restart isuride-php.service
matcher-loop:
	docker compose exec nginx /bin/sh -c "while true; do curl -k -H 'Host:isuride.xiv.isucon.net' https://127.0.0.1/api/internal/matching; sleep 1 ; done"
matcher:
	docker compose exec nginx /bin/sh -c "curl -k -H 'Host:isuride.xiv.isucon.net' https://127.0.0.1/api/internal/matching"
compose:
	docker compose exec php composer install