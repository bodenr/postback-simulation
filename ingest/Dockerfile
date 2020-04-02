FROM php:7.4.3-apache

# rdkafka provides PHP based producer/consumer API for Kafka
RUN apt update && \
    apt install -y librdkafka-dev && \
    pecl install rdkafka && \
    echo "extension=rdkafka.so" > $PHP_INI_DIR/conf.d/rdkafka-ext.ini

WORKDIR /var/www/html
COPY . .
