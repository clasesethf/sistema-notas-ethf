FROM php:8.4-apache
RUN apt-get update && apt-get install -y \
	libfreetype-dev \
	libjpeg62-turbo-dev \
	libpng-dev \
	libldap2-dev \
	libffi-dev \
	libzip-dev \
	git \
	zip \
	&& docker-php-ext-configure gd --with-freetype --with-jpeg \
	&& docker-php-ext-install -j$(nproc) gd \
	&& docker-php-ext-install ldap \
	&& docker-php-ext-install ffi \
	&& docker-php-ext-install zip

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Enable Apache's mod_rewrite module
RUN a2enmod rewrite

# Copy your application files first
COPY . /var/www/html

# Set working directory to your application's root
WORKDIR /var/www/html

# Now, run composer to install dependencies based on composer.json
# Using 'composer install' is better than 'composer require' for deploying
# as it installs based on composer.lock (if present) ensuring consistent dependencies.
# '--no-dev' skips development dependencies, and '--optimize-autoloader'
# optimizes Composer's autoloader for production.
RUN composer install --no-dev --optimize-autoloader

RUN chmod -R 777 .