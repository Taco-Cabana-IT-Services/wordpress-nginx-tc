# Rebuild the WordPress EC2 servers

**One process: staging first, then production.** Build a fresh server, allow only your IP during setup, copy the existing site, test, then move traffic. Leave the old instance/disk in place for recovery.

Verified October 21, 2026:

| Component | Target |
| --- | --- |
| Ubuntu | **26.04 LTS**, official Canonical EC2 image |
| PHP | **8.5**, latest security-updated Ubuntu packages |
| WordPress | **7.1.3**, latest stable release today |
| Web/database/cache | Ubuntu packages: NGINX, MySQL 8.4, Redis |

Ubuntu's PHP build can show `8.5.4` with backported fixes rather than upstream's current `8.5.12`. For these CVEs, Ubuntu 26.04's fixed revision is **`8.5.4-0ubuntu1.5` or later**. This guide chooses official Ubuntu packages, without a PPA or Ubuntu Pro. See [Ubuntu releases](https://ubuntu.com/about/release-cycle), [Ubuntu PHP security notice](https://ubuntu.com/security/notices/USN-8948-1), [upstream PHP](https://www.php.net/downloads.php), and [WordPress releases](https://wordpress.org/download/releases/).

Run commands individually and stop on errors. **WSL** means your computer; **new server** means SSH into the replacement as `ubuntu`. Replace `REPLACE_...` values first. The commands assume the WordPress database is local MySQL; confirm `DB_HOST` in the old `wp-config.php` before starting.

## 1. EC2: launch with the old settings and a new OS

1. Record the old instance's region, type, VPC/subnet, key pair, IAM role, volumes, Elastic IP and DNS/CloudFront/load-balancer routing. Create a recovery AMI via **Actions → Image and templates → Create image**; the normal consistency option reboots, so schedule that interruption. Also take the database/files backup in step 4.
2. Select the old instance → **Actions → Image and templates → Launch more like this**.
3. **Replace the copied AMI** with **Ubuntu Server 26.04 LTS**, publisher **Canonical**, x86_64/amd64 to match the existing servers. Name it `tc-staging-2026` or `tc-prod-2026`. Do not launch the old recovery AMI as the upgraded server.
4. Keep the same region, VPC/subnet and SSH key pair. Start with **4 GiB RAM** for this combined stack; this matches the production README's memory and increases the old 1 GiB staging size. Use a new **50 GiB encrypted gp3 root disk**. Recheck storage after changing the AMI: storage settings are not copied by this wizard.
5. Use a **new security group**, for example `tc-staging-build`. Remove every copied security group from the new instance. Add only these inbound rules:

   | Port | Source |
   | --- | --- |
   | TCP 22 | Your current public IPv4 address `/32` — console **My IP** |
   | TCP 80 | The same `/32` |
   | TCP 443 | The same `/32` |

   No inbound MySQL/Redis ports, `0.0.0.0/0`, `::/0`, or broadly allowed IPv6. Keep outbound access for package downloads, DNS, AWS APIs and certificate renewal. AWS combines rules from **all** attached groups, so retaining an old public group would defeat this restriction.
6. For this simple SSH setup, use the existing public subnet with **auto-assign public IPv4 enabled**. A public address plus these restricted rules allows only your IP inbound; it is not a private-subnet deployment. Leave live DNS and the old Elastic IP untouched. Clear copied **user data** so old install scripts cannot run, and require **IMDSv2** in Advanced details.
7. For automated certificates, create a separate **EC2 IAM role** for the replacement. Copy only the old role permissions still needed (for example S3 backups/SSM), then add an inline policy based on [setup/route53-certbot-policy.json](setup/route53-certbot-policy.json). Replace its hosted-zone ID with the **public** `tacocabana.com` zone ID. Staging's record name is already supplied; for production replace the `route53:ChangeResourceRecordSetsNormalizedRecordNames` array with:

   ```json
   [
     "_acme-challenge.tacocabana.com",
     "_acme-challenge.www.tacocabana.com",
     "_acme-challenge.marketing.tacocabana.com",
     "_acme-challenge.email.tacocabana.com"
   ]
   ```

   Attach this role under Advanced details → IAM instance profile. This permits certificate TXT challenges, not website routing changes. Using a separate role avoids changing the old server's role. No static AWS keys are needed on the new machine.
8. Launch, wait for status checks, and SSH from WSL using the selected key:

   ```bash
   ssh -i /path/to/your-key.pem ubuntu@REPLACE_NEW_PUBLIC_IP
   ```

[AWS launch-copy behavior](https://docs.aws.amazon.com/AWSEC2/latest/UserGuide/launch-more-like-this.html), [security groups](https://docs.aws.amazon.com/AWSEC2/latest/UserGuide/security-group-rules-reference.html), [certificate plugin](https://certbot-dns-route53.readthedocs.io/en/stable/), [Route 53 policy conditions](https://docs.aws.amazon.com/Route53/latest/DeveloperGuide/specifying-conditions-route53.html).

## 2. New server: update and install the stack

```bash
sudo apt update
sudo apt full-upgrade
sudo reboot
```

Reconnect, then:

```bash
sudo apt update
sudo apt install nginx mysql-server redis-server \
  php8.5-fpm php8.5-cli php8.5-mysql php8.5-curl php8.5-gd \
  php8.5-mbstring php8.5-xml php8.5-zip php8.5-intl php8.5-bcmath \
  php-redis php-imagick ghostscript \
  certbot python3-certbot-dns-route53 \
  curl ca-certificates rsync unzip gettext-base cron unattended-upgrades
sudo systemctl enable --now nginx mysql redis-server php8.5-fpm cron
sudo timedatectl set-timezone America/Chicago
apt-cache policy php8.5-fpm php8.5-cli php-redis php-imagick
php -v
php -m
```

**Stop if FPM/CLI are below the fixed Ubuntu revision above.** Confirm `redis` and `imagick` appear in `php -m` and these extensions depend on PHP 8.5. [PHP 8.5 includes OPcache](https://www.php.net/manual/en/migration85.incompatible.php); leave the distribution's extension loading in place. Add other extensions only if the application actually requires them. Keep SSH key authentication and the image's hostname/network defaults.

The following settings assume **4 GiB RAM**. Create `/etc/php/8.5/fpm/conf.d/99-wordpress.ini` with `sudoedit`:

```ini
memory_limit = 256M
upload_max_filesize = 64M
post_max_size = 72M
max_execution_time = 120
max_input_time = 120
display_errors = Off
log_errors = On
opcache.enable = 1
opcache.memory_consumption = 128
opcache.validate_timestamps = 1
opcache.revalidate_freq = 2
opcache.jit_buffer_size = 0
```

In `/etc/php/8.5/fpm/pool.d/www.conf`, change the existing `pm` settings:

```ini
pm = ondemand
pm.max_children = 5
pm.process_idle_timeout = 10s
pm.max_requests = 500
```

Validate and restart:

```bash
sudo /usr/sbin/php-fpm8.5 -t
sudo systemctl restart php8.5-fpm
```

## 3. New server: create the database and configure Redis

Run `sudo mysql`, then this SQL, replacing the password with a new password-manager value:

```sql
CREATE DATABASE tc_wordpress CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'tc_wp'@'localhost' IDENTIFIED BY 'REPLACE_WITH_UNIQUE_DB_PASSWORD';
GRANT ALL PRIVILEGES ON tc_wordpress.* TO 'tc_wp'@'localhost';
SET PERSIST binlog_expire_logs_seconds = 604800;
SET PERSIST binlog_expire_logs_auto_purge = ON;
EXIT;
```

Keep MySQL bound to loopback in `/etc/mysql/mysql.conf.d/mysqld.cnf` (`bind-address = 127.0.0.1`, and loopback for `mysqlx-bind-address` if enabled). Keep the package's initial memory settings; do not copy the old 4 GiB buffer-pool tuning. MySQL 8.4 uses modern authentication; do not import the old `mysql` system database or enable `mysql_native_password` to recreate old users.

In `/etc/redis/redis.conf`, edit the existing settings:

```text
bind 127.0.0.1 ::1
protected-mode yes
maxmemory 128mb
maxmemory-policy allkeys-lru
requirepass REPLACE_WITH_UNIQUE_REDIS_PASSWORD
```

```bash
sudo systemctl restart mysql redis-server
redis-cli --askpass PING
sudo ss -lntp
```

Expect Redis `PONG`; MySQL/Redis listeners must be loopback-only. Each instance has its own database/cache. [Ubuntu database changes](https://documentation.ubuntu.com/release-notes/26.04/summary-for-lts-users/#mysql).

## 4. WSL: back up and copy the existing site

These commands stream the backup to your computer, avoiding a large archive on the nearly full old root disk. Use a protected local directory outside this repository. The source EC2 security group must also allow your current IP for SSH.

```bash
umask 077
TC_OLD_IP=REPLACE_OLD_PUBLIC_IP
TC_NEW_IP=REPLACE_NEW_PUBLIC_IP
TC_SSH_KEY=/path/to/your-key.pem
TC_BACKUP="$HOME/tc-migration/staging-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$TC_BACKUP"
```

Read `DB_NAME` from the old site's `wp-config.php` privately. In the next command replace the final `tc_wordpress` with that actual source database name; old staging may use a different name.

```bash
ssh -i "$TC_SSH_KEY" ubuntu@"$TC_OLD_IP" \
  'sudo mysqldump --single-transaction --quick --no-tablespaces --set-gtid-purged=OFF tc_wordpress' \
  > "$TC_BACKUP/database.sql"
ssh -i "$TC_SSH_KEY" ubuntu@"$TC_OLD_IP" \
  'sudo tar -C /var/www/html/wordpress \
    --exclude=./cache --exclude=./logs --exclude=./.git \
    --exclude=./.ddev --exclude=./.vscode --exclude=./.user.ini \
    --exclude=./wp-content/cache --exclude=./wp-content/updraft --exclude=./wp-content/ai1wm-backups \
    --exclude=./wp-content/object-cache.php --exclude=./wp-content/advanced-cache.php \
    -czf - .' > "$TC_BACKUP/wordpress.tar.gz"
tar -tzf "$TC_BACKUP/wordpress.tar.gz" >/dev/null
ls -lh "$TC_BACKUP/database.sql" "$TC_BACKUP/wordpress.tar.gz"
ssh -i "$TC_SSH_KEY" ubuntu@"$TC_NEW_IP" 'mkdir -m 0700 -p ~/tc-import ~/tc-setup'
scp -i "$TC_SSH_KEY" "$TC_BACKUP/database.sql" "$TC_BACKUP/wordpress.tar.gz" \
  ubuntu@"$TC_NEW_IP":tc-import/
```

From this repository directory in WSL, copy the supplied setup files:

```bash
scp -i "$TC_SSH_KEY" setup/* ubuntu@"$TC_NEW_IP":tc-setup/
```

Check every export/transfer succeeds. `--single-transaction` covers transactional tables; if any site tables use MyISAM, freeze writes for a consistent export. This first copy is for testing; take a final consistent copy at cutover. A Git clone alone lacks the live database/uploads/plugins. Keep backups encrypted/restricted: they contain credentials and site data. [MySQL export](https://dev.mysql.com/doc/refman/8.4/en/mysqldump.html).

## 5. New server: restore into current WordPress

Install WP-CLI from its [official installation instructions](https://make.wordpress.org/cli/handbook/guides/installing/):

```bash
curl -fsSLo /tmp/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
sudo install -m 0755 /tmp/wp-cli.phar /usr/local/bin/wp
sudo install -d -o www-data -g www-data /var/www/html/wordpress /var/www/.wp-cli
sudo install -d -m 0700 /root/tc-import/source
sudo tar -xzf /home/ubuntu/tc-import/wordpress.tar.gz -C /root/tc-import/source
cd /var/www/html/wordpress
sudo -u www-data -H wp core download --version=7.1.3
sudo rsync -a /root/tc-import/source/wp-content/ /var/www/html/wordpress/wp-content/
sudo cp /root/tc-import/source/wp-config.php /var/www/html/wordpress/wp-config.php
sudo chown -R www-data:www-data /var/www/html/wordpress
sudo find /var/www/html/wordpress -type d -exec chmod 0755 {} +
sudo find /var/www/html/wordpress -type f -exec chmod 0644 {} +
sudo chmod 0640 /var/www/html/wordpress/wp-config.php
sudo mysql tc_wordpress < /home/ubuntu/tc-import/database.sql
```

Before any web request or WordPress command that loads the site, edit `wp-config.php`:

- Set `DB_NAME=tc_wordpress`, `DB_USER=tc_wp`, the **new** DB password, and `DB_HOST=localhost`.
- Preserve `$table_prefix`, existing salts/encryption constants, custom configuration and the README's `CONCATENATE_SCRIPTS=false` setting. Existing salts may protect saved plugin credentials. Edit existing definitions; place new ones before `wp-settings.php` is loaded.
- Set `WP_ENVIRONMENT_TYPE` to `staging` and `DISABLE_WP_CRON` to `true` during **both** replacement builds.
- Set `WP_HTTP_BLOCK_EXTERNAL` to `true` and `WP_ACCESSIBLE_HOSTS` to `api.wordpress.org,downloads.wordpress.org,www-staging.tacocabana.com` while preparing staging (use the production hostnames for that build). Keep production email/payment/webhook jobs and credentials disabled; these constants cover WordPress HTTP calls, not custom direct network clients.
- Set `WP_REDIS_HOST=127.0.0.1`, `WP_REDIS_PASSWORD` to the new Redis password and `WP_REDIS_PREFIX` to `tc-staging:` or `tc-prod:`. Keep the default separate local Redis database.

For example, these are PHP definitions, not shell commands:

```php
define('WP_ENVIRONMENT_TYPE', 'staging');
define('DISABLE_WP_CRON', true);
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('WP_ACCESSIBLE_HOSTS', 'api.wordpress.org,downloads.wordpress.org,www-staging.tacocabana.com');
define('WP_REDIS_HOST', '127.0.0.1');
define('WP_REDIS_PASSWORD', 'REPLACE_WITH_UNIQUE_REDIS_PASSWORD');
define('WP_REDIS_PREFIX', 'tc-staging:');
```

On the **new server only**, add `127.0.0.1 www-staging.tacocabana.com` to `/etc/hosts` so self-requests reach the clone. For production use `127.0.0.1 www.tacocabana.com tacocabana.com`. Keep its existing localhost/EC2 hostname entries. This changes local self-requests, not public DNS.

Add the supplied guard before loading the cloned site; it suppresses WordPress email and indexing outside production:

```bash
sudo install -d -o www-data -g www-data /var/www/html/wordpress/wp-content/mu-plugins
sudo install -m 0644 /home/ubuntu/tc-setup/tc-build-guard.php /var/www/html/wordpress/wp-content/mu-plugins/tc-build-guard.php
sudo -u www-data -H wp core update-db --skip-plugins --skip-themes
sudo -u www-data -H wp core verify-checksums
sudo -u www-data -H wp plugin list
```

Keep the environment's **existing domain**; do not replace WordPress URLs with the new IP. Preserve the `blank` parent theme, `taco-cabana` child theme, ACF/ACF Extended, `acf-json`, MU plugins (including TC Control Room), uploads and compiled CSS/JS. Review source-root files for any custom verification files that also need copying; fresh core intentionally replaces the old core.

Back up the restored DB before plugin updates. Update ordinary plugins individually with `wp plugin update PLUGIN_SLUG`; update premium ACF/plugins through their licensed channels and custom themes through the project. If an old MU plugin fails on PHP 8.5, fix/update it before continuing (`--skip-plugins` does not skip MU plugins). Build changed theme assets locally using the application README; Node/DDEV are not required on the server to serve compiled assets.

After updating the existing Redis Object Cache plugin (slug `redis-cache`), enable its new drop-in:

```bash
sudo -u www-data -H wp redis enable
sudo -u www-data -H wp redis status
```

If it is absent, install/activate `redis-cache` first. Restore other cache drop-ins only through their current plugins. No `wp core install` is needed for an imported database. [WordPress migration](https://developer.wordpress.org/advanced-administration/upgrade/migrating/), [PHP compatibility](https://make.wordpress.org/core/handbook/references/php-compatibility-and-wordpress-versions/), [Redis configuration](https://github.com/rhubarbgroup/redis-cache#configuration).

## 6. New server: HTTPS without public inbound access

Use Let's Encrypt with Route 53 DNS validation and the instance role from step 1. It creates temporary TXT records; the website's A/AAAA/origin records stay pointed at the old server. Port 80 does not need to be publicly opened for this method.

For staging:

```bash
sudo certbot certonly --dns-route53 --cert-name tc-wordpress \
  --agree-tos --email webadmin@tacocabana.com \
  -d www-staging.tacocabana.com \
  --deploy-hook 'nginx -t && systemctl reload nginx'
```

For production, use the same command with `-d www.tacocabana.com -d tacocabana.com` instead. Keep the `tc-wordpress` certificate name used by the supplied NGINX template. If role/DNS permissions fail, fix those permissions; do not open the server publicly as a workaround. Certificate automation is covered by the [DNS plugin documentation](https://certbot-dns-route53.readthedocs.io/en/stable/). This is a self-contained Let's Encrypt path; the existing [AWS ACME plan](ssl-automation-plan/README.md) is a separate alternative, not a prerequisite.

## 7. New server: enable NGINX

Choose the hostname values for this environment (re-enter after reconnecting):

```bash
export TC_HOST=www-staging.tacocabana.com
export TC_SERVER_NAMES=www-staging.tacocabana.com
```

For production, use `TC_HOST=www.tacocabana.com` and `TC_SERVER_NAMES='www.tacocabana.com tacocabana.com'` instead.

```bash
sudo install -d -o www-data -g www-data /var/cache/nginx/tc-wordpress
envsubst '${TC_HOST} ${TC_SERVER_NAMES}' < /home/ubuntu/tc-setup/wordpress.nginx.conf \
  | sudo tee /etc/nginx/sites-available/wordpress.conf >/dev/null
sudo rm -f /etc/nginx/sites-enabled/default
sudo ln -s /etc/nginx/sites-available/wordpress.conf /etc/nginx/sites-enabled/wordpress.conf
sudo nginx -t
sudo systemctl reload nginx
sudo systemctl enable --now certbot.timer
sudo certbot renew --dry-run
```

Keep the distribution's `/etc/nginx/nginx.conf` and modules. Do not overwrite `/etc/nginx` with this old repository. The new template uses PHP 8.5, current TLS syntax, protected backup/upload paths and rotated `/var/log/nginx` logs. HTML caching starts disabled for testing; Redis and OPcache are separate.

**Production only:** confirm live routing for `orders.tacocabana.com`, `marketing.tacocabana.com`, `*.marketing.tacocabana.com` and `email.tacocabana.com`. These are additional services in the existing configuration; they must move with the instance if currently used. Do not confuse `orders.` with a separate `order.` CloudFront hostname. After confirming them, request the additional proxy certificate and enable the supplied configuration:

```bash
sudo certbot certonly --dns-route53 --cert-name tc-proxies \
  --agree-tos --email webadmin@tacocabana.com \
  -d marketing.tacocabana.com -d '*.marketing.tacocabana.com' -d email.tacocabana.com \
  --deploy-hook 'nginx -t && systemctl reload nginx'
sudo install -m 0644 /home/ubuntu/tc-setup/production-proxies.nginx.conf /etc/nginx/sites-available/tc-proxies.conf
sudo ln -s /etc/nginx/sites-available/tc-proxies.conf /etc/nginx/sites-enabled/tc-proxies.conf
sudo nginx -t
sudo systemctl reload nginx
sudo certbot renew --dry-run
```

The role's production record-name list includes these challenges. The S3 ordering proxy keeps its existing HTTP origin behavior; confirm any HTTPS/CloudFront routing independently.

## 8. Test while only you can connect

On your **Windows computer**, edit `C:\Windows\System32\drivers\etc\hosts` as Administrator. Add the new instance IP with the real environment hostname:

```text
REPLACE_NEW_PUBLIC_IP www-staging.tacocabana.com
```

For production testing add both `www.tacocabana.com` and `tacocabana.com` to that line; test additional proxy names separately. Remove the entries after cutover. Only your computer uses this override. WSL curl can bypass DNS independently:

```bash
curl --resolve www-staging.tacocabana.com:443:REPLACE_NEW_PUBLIC_IP \
  -I https://www-staging.tacocabana.com/
```

Check WordPress Site Health reports PHP 8.5; test login, ACF page editing/saving, layouts, media resizing, forms, locations/order links, redirects, REST API and cache behavior. Test integrations with approved test credentials; the clone must not send customer messages/payments. Compare site/content and database counts with the source. Confirm access fails from a network outside your IP allowlist.

After functional checks, enable HTML caching by changing `fastcgi_cache off;` to `fastcgi_cache tc_wordpress;` in the new site configuration. Run `sudo nginx -t` and reload. Check repeated anonymous GETs can HIT, while authenticated requests, sessions, cart/checkout, queries and POSTs bypass; PHP responses containing Set-Cookie/private cache controls remain uncacheable. Do not restore the old `fastcgi_ignore_headers ... Set-Cookie` setting. Recheck CPU, RAM and response times under representative traffic before production cutover.

```bash
sudo systemctl is-active nginx php8.5-fpm mysql redis-server
sudo journalctl -u php8.5-fpm -u mysql --since '15 minutes ago' --no-pager
sudo tail -n 50 /var/log/nginx/tc-wordpress.error.log
df -h /
free -h
```

Reboot and retest access/services. Configure Ubuntu security updates with `sudo dpkg-reconfigure -plow unattended-upgrades`, inspect enabled APT timers, and schedule required reboots. Before retiring the old server, verify UpdraftPlus/S3 backups and a restore; alert on disk space, update failures and certificate expiry. For journal retention, create `/etc/systemd/journald.conf.d/` if absent, then add `99-retention.conf` containing `[Journal]` and `SystemMaxUse=300M` on separate lines; restart `systemd-journald`. Packaged NGINX log rotation covers the new log paths. [Ubuntu automatic updates](https://documentation.ubuntu.com/server/how-to/software/automatic-updates/).

## 9. Cut over staging, then repeat for production

Schedule a maintenance window for the final export/sync, database upgrade and traffic change.

1. Record the exact traffic change and reversal. If DNS will change, reduce the relevant TTL beforehand. Check **A/AAAA records, aliases, CloudFront origins and load-balancer targets**, not just one A record. Keep owner-only access while preparing the final copy.
2. Freeze **all old-site writers**: editors, APIs/webhooks, external jobs, WordPress cron and queues. A maintenance page alone does not stop all writers. Take the final consistent DB export and site copy using step 4. Freeze new-site writes too. On the **replacement only**, back up the test DB, recreate an empty `tc_wordpress` database and import the final export using its existing `tc_wp` grant. Extract the final archive into a new root-only directory and sync final uploads with `rsync -a --delete SOURCE/wp-content/uploads/ /var/www/html/wordpress/wp-content/uploads/`, then restore ownership to `www-data`. Sync any other identified mutable plugin data separately. Preserve the tested current plugins/themes, fresh core, guard and environment configuration; do not overwrite them with older source copies. Run `wp core update-db` and regenerate the Redis drop-in.
3. For production, set `WP_ENVIRONMENT_TYPE` to `production` so the guard stops suppressing email/indexing. Remove the temporary HTTP block/allowlist and restore the tested production integration settings. Keep staging isolated with its own settings. Keep `DISABLE_WP_CRON=true` and enable exactly one cron runner on the new instance when ready.
4. On the new production security group, permit public web traffic only when ready: ports 80/443 from `0.0.0.0/0`, or the actual required edge/load-balancer sources if that is the established design. Add IPv6 web rules only if it is intentionally routed. Keep SSH restricted and DB/Redis closed. Staging can remain restricted to your IP permanently.
5. Change the actual traffic route: reassign the old **Elastic IP** to the new instance, or update DNS/origin/target routing as recorded. For direct EC2 DNS, use a stable Elastic IP, not the temporary auto-assigned address that changes on stop/start. **No volume detachment.** Enable new writes and the new jobs, keep old jobs stopped, and test the public route immediately after removing your hosts override.
6. For scheduled WordPress tasks, create `/etc/cron.d/tc-wordpress` on the active server with:

   ```cron
   */5 * * * * www-data /usr/bin/flock -n /var/www/html/wordpress/wp-content/.cron.lock /usr/local/bin/wp --path=/var/www/html/wordpress cron event run --due-now 2>&1 | /usr/bin/logger -t tc-wp-cron
   ```

   Keep it absent on the inactive clone. Review actual job results with `sudo journalctl -t tc-wp-cron`. Enable controlled staging schedules only after isolating their side effects.
7. Watch PHP/NGINX/database errors, latency, forms and integrations. Keep the old instance stopped/isolated and recoverable for a short agreed period, then retire it after restore/certificate/backup checks pass. After new writes, rollback requires reconciling database/uploads changes; a traffic switch alone can lose data. The old PHP runtime is still vulnerable, so any recovery must account for that exposure.

## Sources from this project

Reviewed both supplied 2021 guides and the current application README. This replaces their Apache/mod_php install, shared/password SFTP setup and the previous `STEPS.md` workflow. It preserves the current NGINX/FPM/Redis architecture, custom themes/ACF, required runtime constants, backup requirements and production proxy routes. The older upgrade/migration documents now point here.

- [Original staging install guide](</mnt/c/Users/jpdemko/OneDrive - Texas Taco Cabana, L.P/Documents/Work/tacocabana/tacocabana.com/aws install and ssh/staging/staging-install-guide-passwords-stripped.txt>)
- [Original production install guide](</mnt/c/Users/jpdemko/OneDrive - Texas Taco Cabana, L.P/Documents/Work/tacocabana/tacocabana.com/aws install and ssh/prod/prod-install-guide-passwords-stripped.txt>)
- [Application README](/home/jpdemko/tacocabana.com/README.md)

This is an operator guide and configuration set; it has not launched or changed an EC2 instance.
