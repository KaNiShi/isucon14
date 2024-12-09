# イメージを取得
FROM php:8.3.13-fpm
#COPY php.ini /usr/local/etc/php/
WORKDIR /home/isucon/webapp/php/
COPY --from=composer:2.7.2 /usr/bin/composer /usr/bin/composer

RUN apt-get update && apt-get install -y \
	git \
	curl \
	zip \
	unzip \
    && docker-php-ext-install pdo_mysql
