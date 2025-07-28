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
sudo apt-mark hold php8.1*
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
sudo apt-mark unhold php8.1*
```

```
sudo ufw allow "Nginx Full"
```

```
sudo service nginx status
sudo service nginx stop
```

# Install php8.1-fpm

```
sudo apt install php8.1-fpm php8.1-common php8.1-mysql \
php8.1-xml php8.1-xmlrpc php8.1-curl php8.1-gd \
php8.1-imagick php8.1-cli php8.1-dev php8.1-imap \
php8.1-mbstring php8.1-opcache php8.1-redis \
php8.1-soap php8.1-zip -y
php-fpm8.1 -v
```

# Edit php.ini

`sudo nano /etc/php/8.1/fpm/php.ini`

```
memory_limit = 128M
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
```

Step 2: Verify the Phar File This is an important security step to ensure the file you downloaded is not corrupted or malicious.

`php wp-cli.phar --info`

You should see output that includes the PHP version, WP-CLI version, and other details. If you see an error, delete the file and try downloading it again.

Step 3: Make the File Executable This command gives the file permission to be run as a program.

`chmod +x wp-cli.phar`

Step 4: Move the File into Your PATH This command moves the file to /usr/local/bin and renames it to wp. This allows you to simply type wp to execute it, instead of php wp-cli.phar.

`sudo mv wp-cli.phar /usr/local/bin/wp`

Step 5: Test the Installation To confirm it's working correctly, run the following command:

`wp --info`

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
sudo service php8.1-fpm restart
sudo service nginx start
sudo nginx -t
sudo service nginx restart
sudo service nginx status
```

# Setup opcache for php

`sudo nano /etc/php/8.1/fpm/php.ini` and/or `/etc/php/8.1/fpm/conf.d/10-opcache.ini`

```
; configuration for php opcache module
; priority=10
zend_extension=opcache.so

; Enables the OPcache extension
opcache.enable=1

; The size of the shared memory storage used by OPcache, in megabytes.
opcache.memory_consumption=128

; The amount of memory for interned strings in Mbytes.
opcache.interned_strings_buffer=16

; The maximum number of keys (and therefore scripts) in the OPcache hash table.
opcache.max_accelerated_files=16001

; Disables OPcache for the CLI, as it's not needed for short-lived scripts.
opcache.enable_cli=0
; When enabled, OPcache appends the current working directory to the script key.
opcache.use_cwd=1
; Allows OPcache to skip the file existence check for includes/requires.
opcache.enable_file_override=1

; Validates file permissions before caching or serving from cache.
opcache.validate_permission=1
; Checks for conflicting filenames in the include_path.
opcache.revalidate_path=1
; How often (in seconds) to check script timestamps for updates.
opcache.revalidate_freq=30
; If enabled, OPcache will check for updated scripts.
opcache.validate_timestamps=1

; This MUST be enabled for WordPress.
opcache.save_comments=1

; Crashes wp-admin for some reason? Might be fixed in php8.3 > or later WP versions.
; The amount of shared memory to reserve for the JIT compiler.
;opcache.jit_buffer_size=100M
; Enables Tracing JIT. This is the key setting.
;opcache.jit=1255

; Enables a faster shutdown mechanism for OPcache.
opcache.fast_shutdown=1
```

Exit nano.

```

```

# PHP-FPM Pool (/etc/php/8.1/fpm/pool.d/www.conf)

```
# Use the dynamic process manager, which is flexible for varying traffic.
pm = dynamic

# This is the most important setting.
# Calculation: (RAM / 2) / 60 = MAX (prob safe lower)
# We'll start with 60 as a safe number.
pm.max_children = 30

# Start with a healthy number of processes ready to go.
# (2 vCPUs * 4) = 8, but we can be more aggressive. Let's use 15.
pm.start_servers = 10

# Keep at least this many processes waiting for requests.
# (2 vCPUs * 2) = 4, but let's use 10.
pm.min_spare_servers = 5

# Don't let the number of idle processes grow too large.
pm.max_spare_servers = 15

# If a process handles this many requests, it will be automatically restarted.
# This helps prevent memory leaks from long-running plugins.
pm.max_requests = 500
```

# Custom MySQL settings (/etc/mysql/mysql.conf.d/z-custom-tuning.cnf)

```
[mysqld]
# Cache for InnoDB data and indexes to reduce disk I/O.
innodb_buffer_pool_size = 1G

# A modest buffer for MyISAM tables.
# Cache for MyISAM table indexes.
key_buffer_size = 128M

# Increase the maximum allowed connections.
# Maximum number of simultaneous database connections.
max_connections = 200
```

``

# Setup redis for site

```
sudo apt install redis-server
```

`sudo nano /etc/redis/redis.conf`

```
# Integrates Redis with the systemd service manager for better process supervision.
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

# Sets a hard memory limit to prevent Redis from using all available RAM.
maxmemory 256MB

# When memory is full, evicts the least recently used keys to make space.
maxmemory-policy allkeys-lru

# Secures the Redis server by requiring a password for all connections.
requirepass BIGFKNSTRING
```

- Uncomment `requirepass` and paste value to right of key
- Exit nano.
- Just in case; do the same via the redis-cli...

```
redis-cli -a BIGFKNSTRING
config set maxmemory 256MB
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
sudo systemctl start nginx
sudo systemctl status nginx
```

```
sudo systemctl status apache2
sudo systemctl enable apache2
sudo systemctl start apache2
sudo systemctl status apache2
```

```
sudo a2dismod php8.1
sudo a2dismod mpm_prefork
sudo a2enmod mpm_event proxy_fcgi setenvif
sudo a2enconf php8.1-fpm
```

## References

- https://spinupwp.com/hosting-wordpress-yourself-nginx-php-mysql/
- https://www.digitalocean.com/community/tutorials/how-to-install-and-secure-redis-on-ubuntu-20-04
