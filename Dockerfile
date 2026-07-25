FROM php:8.2-apache

RUN apt-get update && apt-get install -y libcurl4-openssl-dev \
    && docker-php-ext-install mysqli curl \
    && rm -rf /var/lib/apt/lists/*

# Pastikan hanya SATU MPM yang aktif. Pembaruan paket apache2 dari repositori Debian
# bisa mengaktifkan mpm_event, padahal image php:apache memakai mpm_prefork (yang
# dibutuhkan mod_php). Dua MPM aktif membuat Apache menolak start dengan AH00534.
RUN a2dismod mpm_event mpm_worker 2>/dev/null || true; \
    a2enmod mpm_prefork rewrite

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html/cache \
    && chmod -R 775 /var/www/html/cache

EXPOSE 80
