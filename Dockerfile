# Gunakan base image dengan PHP dan Apache
FROM php:8.2-apache

# Install dependencies yg dibutuhkan: ffmpeg, python3, pip, yt-dlp, unzip, curl
RUN apt-get update && apt-get install -y \
    ffmpeg \
    python3 \
    python3-pip \
    unzip \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Install yt-dlp via pip
RUN pip3 install --upgrade yt-dlp

# Copy kode PHP ke folder apache webroot
COPY . /var/www/html/

# Beri permission folder logs dan downloads supaya bisa ditulis
RUN mkdir -p /var/www/html/downloads /var/www/html/logs && \
    chown -R www-data:www-data /var/www/html/downloads /var/www/html/logs

# Expose port 80
EXPOSE 80
