#!/bin/sh

# Laravel requires some directories to be writable.
sudo chmod +x /var/www/html/artisan
sudo chown -R root:root /var/www/html
sudo chmod -R 777 storage/
sudo chmod -R 777 bootstrap/cache/