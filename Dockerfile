FROM php:8.2-apache

RUN apt-get update && apt-get install -y libcurl4-openssl-dev \
    && docker-php-ext-install mysqli curl \
    && rm -rf /var/lib/apt/lists/*

# Apache hanya boleh memuat SATU MPM; dua MPM aktif membuatnya menolak start
# dengan "AH00534: More than one MPM loaded".
#
# a2dismod bisa gagal tanpa menghentikan build, jadi symlink MPM dihapus langsung —
# cara ini pasti. Setelah itu tepat satu MPM dinyalakan kembali (mpm_prefork, yang
# dibutuhkan mod_php). Baris terakhir mencetak MPM yang aktif ke build log supaya
# kondisinya bisa dilihat, bukan ditebak.
RUN rm -f /etc/apache2/mods-enabled/mpm_*.load \
          /etc/apache2/mods-enabled/mpm_*.conf \
    && a2enmod mpm_prefork rewrite \
    && echo "=== MPM aktif: ===" \
    && ls -1 /etc/apache2/mods-enabled/ | grep mpm

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html/cache \
    && chmod -R 775 /var/www/html/cache

EXPOSE 80
