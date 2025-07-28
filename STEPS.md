# Create SU that's not root

```
adduser pdemko
usermod -a -G sudo pdemko
```

# Add site name to hostname

```
echo "tacocabana.com" > /etc/hostname
hostname -F /etc/hostname
```

# Install server firewall for fail2ban

This is not really needed because of AWS Sec. Groups, but fail2ban needs it.

```
sudo apt install ufw
sudo ufw allow ssh
sudo ufw allow http
sudo ufw allow https
sudo ufw allow "Apache Full"
sudo ufw show added
sudo ufw enable
sudo ufw status verbose
```

# Install nginx and update packages

```
sudo apt-mark hold mysql*
sudo apt-mark hold phpXXX*
```

```
sudo add-apt-repository ppa:ondrej/nginx -y
sudo apt update
sudo apt dist-upgrade -y
sudo apt install nginx -y
sudo apt autoremove
nginx -v
```

```
sudo apt-mark unhold mysql*
sudo apt-mark unhold phpXXX*
```

```
sudo ufw allow "Nginx Full"
```

```
sudo service nginx status
sudo service nginx stop
```

# Install phpXXX-fpm

```
sudo apt install phpXXX-fpm phpXXX-common phpXXX-mysql \
phpXXX-xml phpXXX-xmlrpc phpXXX-curl phpXXX-gd \
phpXXX-imagick phpXXX-cli phpXXX-dev phpXXX-imap \
phpXXX-mbstring phpXXX-opcache phpXXX-redis \
phpXXX-soap phpXXX-zip -y
php-fpmXXX -v
```

# Edit php.ini

`sudo nano /etc/php/XXX/fpm/php.ini`

```
memory_limit = 256M
post_max_size = 64M
max_input_time = 120
max_execution_time = 120
```

Exit nano.

For making sure the PHP path on the server is the same we're using on WP

```
sudo update-alternatives --config php
```

# Install wp-cli on non-root SU

`su pdemko`

```
cd ~/
curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
php wp-cli.phar --info
```

`sudo -s`

# Get ready to clone NGINX config

```
mkdir /var/www/html/wordpress/logs
mkdir /var/www/html/wordpress/cache
```

```
cd /etc/
git clone https://github.com/Taco-Cabana-IT-Services/wordpress-nginx-tc.git
mv ./nginx ./nginx-backup
mv ./wordpress-nginx-tc ./nginx
cd /etc/nginx
```

Symlink sites-available to sites-enabled.

```
sudo ln -s /etc/nginx/sites-available/tacocabana.com /etc/nginx/sites-enabled/tacocabana.com
```

```
sudo systemctl disable apache2
sudo systemctl stop apache2
```

```
sudo service phpXXX-fpm restart
sudo service nginx start
sudo nginx -t
sudo service nginx restart
sudo service nginx status
```

# Setup opcache for php

`sudo nano /etc/php/XXX/fpm/php.ini` and/or `/etc/php/XXX/fpm/conf.d/10-opcache.ini`

```
; configuration for php opcache module
; priority=10
zend_extension=opcache.so

; Enables the OPcache extension
opcache.enable=1

; The size of the shared memory storage used by OPcache, in megabytes.
opcache.memory_consumption=128

; The amount of memory for interned strings in Mbytes.
opcache.interned_strings_buffer=8

; The maximum number of keys (and therefore scripts) in the OPcache hash table.
opcache.max_accelerated_files=10000

; How often (in seconds) to check script timestamps for updates.
opcache.revalidate_freq=60

; If enabled, OPcache will check for updated scripts.
opcache.validate_timestamps=1

; This MUST be enabled for WordPress.
opcache.save_comments=1

; The amount of shared memory to reserve for the JIT compiler.
opcache.jit_buffer_size=100M

; Enables Tracing JIT. This is the key setting.
opcache.jit=1255

opcache.fast_shutdown=1
```

Exit nano.

```
sudo service phpXXX-fpm restart
```

# PHP-FPM Pool (/etc/php/XXX/fpm/pool.d/www.conf)

```
; Use the dynamic process manager, which is flexible for varying traffic.
pm = dynamic

; This is the most important setting.
; Calculation: (4096MB total for PHP) / (avg 60MB per process) = ~68
; We'll start with 60 as a safe number.
pm.max_children = 60

; Start with a healthy number of processes ready to go.
; (2 vCPUs * 4) = 8, but we can be more aggressive. Let's use 15.
pm.start_servers = 15

; Keep at least this many processes waiting for requests.
; (2 vCPUs * 2) = 4, but let's use 10.
pm.min_spare_servers = 10

; Don't let the number of idle processes grow too large.
pm.max_spare_servers = 20

; If a process handles this many requests, it will be automatically restarted.
; This helps prevent memory leaks from long-running plugins.
pm.max_requests = 500
```

# Custom MySQL settings

```
# Custom performance tuning for 8GB RAM server

[mysqld]
# Set the InnoDB buffer pool to 3GB. This is the most important setting.
innodb_buffer_pool_size = 3G

# A modest buffer for MyISAM tables.
key_buffer_size = 128M

# Increase the maximum allowed connections.
max_connections = 200
```

`sudo systemctl restart mysql`

# Setup redis for site

```
sudo apt install redis-server
```

`sudo nano /etc/redis/redis.conf`

```
supervised systemd
```

Exit nano.

```
sudo systemctl restart redis.service
sudo systemctl status redis # Should be enabled and running (for system restart)
```

`redis-cli`

```
ping
exit
```

`openssl rand 60 | openssl base64 -A`

```
BIGFKNSTRING
```

```
# /etc/redis/redis.conf

# Set a maximum memory limit of 512MB. This is plenty for object caching.
maxmemory 512MB

# When the limit is reached, remove the least recently used (LRU) keys.
# This is the ideal policy for a cache.
maxmemory-policy allkeys-lru

requirepass BIGFKNSTRING
```

- Uncomment `requirepass` and paste value to right of key
- Exit nano.
- Just in case; do the same via the redis-cli...

```
redis-cli -a BIGFKNSTRING
config set maxmemory 512M
```

`sudo systemctl restart redis-server`

# Setup fail2ban

### TODOs

- WP fail2ban by Charles Lecklider, then configure settings

```
sudo apt install fail2ban
sudo service fail2ban start
sudo curl https://plugins.svn.wordpress.org/wp-fail2ban/trunk/filters.d/wordpress-hard.conf > /etc/fail2ban/filter.d/wordpress.conf
```

..._then_...

`sudo nano /etc/fail2ban/jail.d/wordpress.conf`

```
[wordpress]
enabled = true
filter = wordpress
logpath = /var/log/auth.log
port = http,https
```

Exit nano.

`sudo nano /etc/fail2ban/jail.conf`

```
maxretry = 5
findtime = 2h
bantime = 4h
```

Exit nano.

```
sudo service fail2ban restart
```

# Final reset permissions for site folder

```
sudo chown -R www-data:www-data /var/www/html* # Let Apache be owner
sudo find /var/www/html/ -type d -exec chmod 755 {} \; # Recursively change directory permissions to rwxr-xr-x
sudo find /var/www/html/ -type f -exec chmod 664 {} \; # Recursively change file permissions to rw-r--r--
```

# NGINX switch failure, switch back to Apache

```
sudo systemctl stop nginx
sudo systemctl disable nginx
sudo systemctl status nginx
```

```
sudo systemctl status apache2
sudo systemctl enable apache2
sudo systemctl start apache2
sudo systemctl status apache2
```

```
sudo a2dismod phpXXX
sudo a2dismod mpm_prefork
sudo a2enmod mpm_event proxy_fcgi setenvif
sudo a2enconf phpXXX-fpm
```

## References

- https://spinupwp.com/hosting-wordpress-yourself-nginx-php-mysql/
- https://www.digitalocean.com/community/tutorials/how-to-install-and-secure-redis-on-ubuntu-20-04
