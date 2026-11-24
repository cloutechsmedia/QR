FROM php:8.2-apache

# Install MySQL PDO extension & CA certificates (required for TiDB Cloud TLS)
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates \
    && docker-php-ext-install pdo pdo_mysql \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Enable .htaccess overrides
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

WORKDIR /var/www/html
COPY . /var/www/html

# Ensure Apache serves on Render's dynamic $PORT (defaults to 8080)
RUN printf '#!/bin/sh\nPORT=${PORT:-8080}\nsed -i "s/Listen 80/Listen ${PORT}/g" /etc/apache2/ports.conf\nsed -i "s/:80>/:${PORT}>/g" /etc/apache2/sites-available/000-default.conf\nexec apache2-foreground\n' > /usr/local/bin/start-apache.sh \
    && chmod +x /usr/local/bin/start-apache.sh

EXPOSE 8080
CMD ["/usr/local/bin/start-apache.sh"]
