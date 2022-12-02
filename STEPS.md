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
memory_limit = 512M
upload_max_filesize = 64M
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
sudo service php8.1-fpm restart
sudo service nginx start
sudo nginx -t
sudo service nginx restart
sudo service nginx status
```

### TODOs

-   Check if website is up
-   NGINX Cache by Till Krüss, then configure settings

# Setup opcache for php

`sudo nano /etc/php/8.1/fpm/php.ini`

```
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=10000
opcache.revalidate_freq=60
opcache.fast_shutdown=1
```

Exit nano.

```
sudo service php8.1-fpm restart
```

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
DzsBOHA6HrDE9X7TkDW0TG8VJBJcnlhiuctdlNPLy4XsqTZ3wuj4ZcJRhVUwZuiR6GpRfW3SVGauLFVk
```

`sudo nano /etc/redis/redis.conf`

-   Uncomment `requirepass` and paste value to right of key
-   Exit nano.

```
redis-cli -a DzsBOHA6HrDE9X7TkDW0TG8VJBJcnlhiuctdlNPLy4XsqTZ3wuj4ZcJRhVUwZuiR6GpRfW3SVGauLFVk
config get maxmemory
config set maxmemory 128M
```

### TODOs

-   Redis Object Cache by Till Krüss, then configure settings

# Setup fail2ban

### TODOs

-   WP fail2ban by Charles Lecklider, then configure settings

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
sudo a2dismod php8.1
sudo a2dismod mpm_prefork
sudo a2enmod mpm_event proxy_fcgi setenvif
sudo a2enconf php8.1-fpm
```

## References

-   https://spinupwp.com/hosting-wordpress-yourself-nginx-php-mysql/
-   https://www.digitalocean.com/community/tutorials/how-to-install-and-secure-redis-on-ubuntu-20-04
