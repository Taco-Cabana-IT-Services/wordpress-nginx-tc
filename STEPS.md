# Rebuild the WordPress servers on Ubuntu 26.04

Ubuntu 20.04 can't get PHP 8.5, and upgrading it in place would take three back-to-back release upgrades on a live server. Instead, build a **new EC2 instance**, copy the site to it, test it from your own computer only, then **move the Elastic IP** to it. The old instance is left untouched, so going back is just moving the IP back.

Do **staging first**, then production. The steps are the same; the differences are in the table below.

Package versions on Ubuntu 26.04 (checked on Launchpad, Oct 21 2026): PHP **8.5.4-0ubuntu1.5**, MySQL **8.4.11**, Redis **8.0.5**, nginx **1.28.3**. The site was tested locally in DDEV on PHP 8.5.9 + MySQL 8.4: all front-end pages and wp-admin screens load with no fatal errors or deprecations.

**WSL** = your computer. **Old server** / **new server** = SSH into that instance as `ubuntu`. Run commands one at a time and stop if one fails. Replace `REPLACE_...` values first.

## Values per environment

| | Production | Staging |
| --- | --- | --- |
| Hostname | `www.tacocabana.com` | `www-staging.tacocabana.com` |
| Elastic IP (current) | `18.219.223.212` | `18.116.129.11` |
| New instance type | t3.medium (4 GB) | t3.small (2 GB) |
| nginx site file | `sites-available/tacocabana.com.conf` | `sites-available/www-staging.tacocabana.com.conf` |
| PHP-FPM `pm.max_children` | 16 | 6 |
| MySQL `innodb_buffer_pool_size` | 1G | 512M |
| Redis `maxmemory` | 256mb | 128mb |
| Site git branch | `main` | `dev` |
| Copy data from | old prod server | old staging server |

What doesn't change: Route 53, CloudFront and S3. `www.`, `marketing.` and `email.` are A records to the Elastic IP, so moving the IP moves all three. The apex `tacocabana.com` goes CloudFront → S3 → `https://www.tacocabana.com` by name, and `orders.` is on Netlify. The certificate stays the GoDaddy wildcard the servers use today (expires Apr 23 2027).

## 0. WSL, once: prepare this repo

This repo gets checked out directly into `/etc/nginx` on the new servers (step 4). It now targets Ubuntu 26.04 and PHP 8.5.

1. Tag the last Ubuntu 20.04 version (commit `a120bd0`, which matches the live servers) so it can be found later, and **never run `git pull` in `/etc/nginx` on an old server** from now on (it would switch it to PHP 8.5 paths):

   ```bash
   cd ~/wordpress-nginx-tc
   git tag ubuntu-20.04 a120bd0
   ```

2. Review, commit and push. This removes the old `modules-enabled/` links (they point at PPA modules Ubuntu 26.04 doesn't have, and nginx won't start if they're checked out), files Ubuntu's package now provides, and the superseded templates in `setup/`:

   ```bash
   git add -A
   git status
   git commit -m "Ubuntu 26.04 / PHP 8.5 server config"
   git push origin main ubuntu-20.04
   ```

## 1. Old server: checks before you start

```bash
cd /var/www/html/wordpress
sudo git status
sudo -u www-data wp core version
sudo -u www-data wp plugin list --fields=name,status,version
```

- `git status` must be clean. Commit and push anything that was changed only on the server, or it gets lost later.
- Compare the plugin list with `ddev wp plugin list` locally. Update the old site to the versions you tested in DDEV, so the move is like-for-like and nothing needs updating mid-migration. If an update refuses to install on PHP 8.1, do it on the new server after go-live.
- Delete plugins you don't use: less to test, less to break.
- Open `wp-config.php` (`sudo less wp-config.php`) and copy `DB_NAME`, `DB_USER`, `DB_PASSWORD` and `WP_REDIS_PASSWORD` into your password manager. The new server reuses them, so `wp-config.php` needs no database changes. Staging may use different values from production.
- EC2 console → **Elastic IPs**: check that the IP in the table is listed there. If staging's isn't, allocate a new Elastic IP for the new staging instance and change the `www-staging` A record to it at go-live instead of moving an IP.

## 2. EC2 console: launch the new instance

1. Select the old instance → **Actions → Image and templates → Launch more like this**.
2. Name: `tc-prod-2026` or `tc-staging-2026`.
3. AMI: replace it with **Ubuntu Server 26.04 LTS** (publisher Canonical, 64-bit x86).
4. Instance type from the table. Key pair: the same one as the old server.
5. Network: same VPC and subnet, **auto-assign public IP: enable**. Security group: **create a new one** (`tc-prod-2026`) with inbound TCP 22, 80 and 443 from **My IP** only, and remove every copied group. AWS combines the rules of all attached groups, so a copied public group would make the new server public.
6. Storage: **40 GiB gp3, encrypted**. The wizard doesn't copy storage settings correctly after an AMI change, so check this.
7. Advanced details: IAM instance profile the same as the old server (or none), **Metadata version: V2 only**, **User data: empty**.
8. Launch, wait for status checks, then from WSL:

   ```bash
   ssh -i /path/to/key.pem ubuntu@REPLACE_NEW_PUBLIC_IP
   ```

## 3. New server: base system and packages

```bash
sudo apt update && sudo apt full-upgrade -y
sudo reboot
```

Reconnect, then:

```bash
sudo timedatectl set-timezone America/Chicago

# 2 GB swap (the AMI has none)
sudo fallocate -l 2G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
echo 'vm.swappiness=10' | sudo tee /etc/sysctl.d/99-swappiness.conf
sudo sysctl --system

# Keep logs from filling the disk
sudo install -d /etc/systemd/journald.conf.d
printf '[Journal]\nSystemMaxUse=300M\n' | sudo tee /etc/systemd/journald.conf.d/99-retention.conf
sudo systemctl restart systemd-journald

sudo apt install -y nginx mysql-server redis-server \
  php8.5-fpm php8.5-cli php8.5-mysql php8.5-curl php8.5-gd php8.5-mbstring \
  php8.5-xml php8.5-zip php8.5-intl php8.5-bcmath php8.5-soap \
  php-redis php-imagick ghostscript git rsync unzip

php -v
php -m | grep -iE '^(redis|imagick|zend opcache)$'
systemctl is-enabled unattended-upgrades
```

`php -v` must show `8.5.4-0ubuntu1.5` or newer, and all three modules must be listed. OPcache is built into PHP 8.5; there's no separate package.

Install WP-CLI:

```bash
curl -fsSLo /tmp/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
php /tmp/wp-cli.phar --info
sudo install -m 0755 /tmp/wp-cli.phar /usr/local/bin/wp
sudo install -d -o www-data -g www-data /var/www/.wp-cli
```

WP-CLI prints a "Case statements followed by a semicolon" deprecation on PHP 8.5. That comes from WP-CLI itself, not the site, and is harmless.

## 4. New server: put this repo in /etc/nginx

Each server gets read-only GitHub **deploy keys** (one per repo; GitHub doesn't allow the same key on two repos). They're used by `root`, which runs the `git` commands.

```bash
sudo install -d -m 700 /root/.ssh
sudo ssh-keygen -t ed25519 -N '' -C "tc-prod-2026 nginx" -f /root/.ssh/deploy-nginx
sudo ssh-keygen -t ed25519 -N '' -C "tc-prod-2026 wordpress" -f /root/.ssh/deploy-wordpress
sudo tee -a /root/.ssh/config >/dev/null <<'EOF'
Host github-nginx
    HostName github.com
    User git
    IdentityFile /root/.ssh/deploy-nginx
    IdentitiesOnly yes

Host github-wordpress
    HostName github.com
    User git
    IdentityFile /root/.ssh/deploy-wordpress
    IdentitiesOnly yes
EOF
sudo cat /root/.ssh/deploy-nginx.pub /root/.ssh/deploy-wordpress.pub
```

On GitHub, add each public key under the repo's **Settings → Deploy keys** (leave "Allow write access" off): `deploy-nginx.pub` on `wordpress-nginx-tc`, `deploy-wordpress.pub` on `tacocabana.com`. Then:

```bash
sudo ssh -T github-nginx        # accept the fingerprint; expect "successfully authenticated"
sudo ssh -T github-wordpress

cd /etc/nginx
sudo git init -b main
sudo git remote add origin github-nginx:Taco-Cabana-IT-Services/wordpress-nginx-tc.git
sudo git fetch origin
sudo git checkout -f -B main --track origin/main
sudo git status
sudo install -d -o www-data -g www-data /var/cache/nginx/tc-wordpress
```

`git checkout -f` replaces Ubuntu's `nginx.conf` with the repo's. Files Ubuntu's package owns (`mime.types`, `fastcgi.conf`, `snippets/`, `modules-enabled/`) stay in place and are ignored by `.gitignore`, so `git status` should say "nothing to commit". The page-cache folder must exist before nginx next starts, or nginx refuses to start. The sites aren't enabled until step 8.

## 5. New server: PHP, MySQL and Redis settings

The settings files come from the repo you just checked out:

```bash
sudo install -m 0644 /etc/nginx/setup/php/99-tacocabana.ini /etc/php/8.5/fpm/conf.d/99-tacocabana.ini
sudo install -m 0644 /etc/nginx/setup/php/www.conf /etc/php/8.5/fpm/pool.d/www.conf
sudo install -m 0644 /etc/nginx/setup/mysql/tacocabana.cnf /etc/mysql/mysql.conf.d/tacocabana.cnf
```

**Staging only** (2 GB), lower the sizes:

```bash
sudo sed -i 's/^pm.max_children = 16/pm.max_children = 6/; s/^pm.max_spare_servers = 6/pm.max_spare_servers = 3/' /etc/php/8.5/fpm/pool.d/www.conf
sudo sed -i 's/^innodb_buffer_pool_size = 1G/innodb_buffer_pool_size = 512M/' /etc/mysql/mysql.conf.d/tacocabana.cnf
```

Redis: run `sudoedit /etc/redis/tacocabana.conf` and paste (staging: `128mb`; the password is the old `WP_REDIS_PASSWORD`):

```text
maxmemory 256mb
maxmemory-policy allkeys-lru
requirepass REPLACE_WITH_WP_REDIS_PASSWORD
```

```bash
sudo chown redis:redis /etc/redis/tacocabana.conf
sudo chmod 640 /etc/redis/tacocabana.conf
echo 'include /etc/redis/tacocabana.conf' | sudo tee -a /etc/redis/redis.conf

sudo php-fpm8.5 -t
sudo systemctl restart php8.5-fpm mysql redis-server
redis-cli --askpass PING
sudo mysql -e "SELECT @@innodb_buffer_pool_size/1024/1024 AS pool_mb, @@log_bin;"
sudo ss -lntp | grep -E ':(3306|33060|6379) '
```

Expect `PONG`, the pool size from the table with `log_bin` 0, and MySQL and Redis listening on `127.0.0.1` only.

Create the database with the **same name, user and password as the old `wp-config.php`**. Production uses `tc_wordpress` / `tc_admin`; on staging, use its own `DB_NAME` and `DB_USER` here and in step 10. Run `sudo mysql` and paste:

```sql
CREATE DATABASE tc_wordpress CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'tc_admin'@'localhost' IDENTIFIED BY 'REPLACE_WITH_OLD_DB_PASSWORD';
GRANT ALL PRIVILEGES ON tc_wordpress.* TO 'tc_admin'@'localhost';
EXIT;
```

## 6. WSL: copy the site, database and certificate

The site is mirrored to your computer with `rsync`. The first run copies everything (uploads are about 2.2 GB); at go-live the same commands only send what changed. The mirror also serves as a backup. Keep it outside any repo, because it contains `wp-config.php`.

```bash
umask 077
TC_KEY=/path/to/key.pem                 # this environment's key pair
TC_OLD=REPLACE_OLD_IP                   # table above
TC_NEW=REPLACE_NEW_PUBLIC_IP
TC_DB=tc_wordpress                      # DB_NAME from the old wp-config.php
TC_WORK="$HOME/tc-migration/prod"       # or .../staging
mkdir -p "$TC_WORK/site"
TC_EXCLUDES=(--exclude=/cache/ --exclude=/logs/ --exclude=/wp-content/cache/
  --exclude=/wp-content/updraft/ --exclude=/wp-content/ai1wm-backups/
  --exclude=/wp-content/upgrade/ --exclude=/wp-content/upgrade-temp-backup/
  --exclude=/wp-content/object-cache.php --exclude=/wp-content/advanced-cache.php
  --exclude=/wp-content/db.php)
```

1. Pull the site from the old server (includes `.git`, `.user.ini` and `wp-config.php`):

   ```bash
   rsync -az --info=progress2 "${TC_EXCLUDES[@]}" -e "ssh -i $TC_KEY" --rsync-path="sudo rsync" \
     ubuntu@"$TC_OLD":/var/www/html/wordpress/ "$TC_WORK/site/"
   ```

2. Export the database:

   ```bash
   ssh -i "$TC_KEY" ubuntu@"$TC_OLD" "set -o pipefail; sudo mysqldump --single-transaction --quick --no-tablespaces $TC_DB | gzip" > "$TC_WORK/db.sql.gz"
   gunzip -c "$TC_WORK/db.sql.gz" | tail -n 1
   ```

   The last line must read `-- Dump completed on ...`.

3. Push the site and the database to the new server:

   ```bash
   rsync -az --info=progress2 --no-owner --no-group -e "ssh -i $TC_KEY" --rsync-path="sudo rsync" \
     "$TC_WORK/site/" ubuntu@"$TC_NEW":/var/www/html/wordpress/
   gunzip -c "$TC_WORK/db.sql.gz" | ssh -i "$TC_KEY" ubuntu@"$TC_NEW" "sudo mysql $TC_DB"
   ```

4. Copy the certificate and key from the **old production** server straight to the new server (nothing is saved on your computer). Staging uses the same wildcard:

   ```bash
   TC_PROD_KEY=/path/to/TC-Prod.pem
   ssh -i "$TC_PROD_KEY" ubuntu@18.219.223.212 \
     'sudo tar -C /etc/ssl -cf - certs/tc.com-2027-fullchain.crt private/threeyear.tacocabana.com.key' \
     | ssh -i "$TC_KEY" ubuntu@"$TC_NEW" \
     'sudo tar -C /etc/ssl -xf - && sudo chmod 600 /etc/ssl/private/threeyear.tacocabana.com.key && sudo openssl x509 -in /etc/ssl/certs/tc.com-2027-fullchain.crt -noout -enddate'
   ```

   Expect `notAfter=Apr 23 ... 2027 GMT`.

## 7. New server: finish WordPress

Before anything loads the site, turn on the **clone guard**, so the copy can't send email or call production APIs while you test. Install the mu-plugin:

```bash
sudo install -m 0644 /etc/nginx/setup/tc-clone-guard.php /var/www/html/wordpress/wp-content/mu-plugins/tc-clone-guard.php
sudoedit /var/www/html/wordpress/wp-config.php
```

In `wp-config.php`, above the `/* That's all, stop editing! */` line, add (edit `DISABLE_WP_CRON` instead if it's already defined):

```php
// --- TEMPORARY while building the new server. Delete this block at go-live (step 10). ---
define('TC_CLONE_GUARD', true);         // blocks all WordPress email (mu-plugins/tc-clone-guard.php)
define('WP_HTTP_BLOCK_EXTERNAL', true); // blocks server-side API calls, except these hosts:
define('WP_ACCESSIBLE_HOSTS', 'api.wordpress.org,downloads.wordpress.org,www.google.com,ordering.api.olo.com,connect.advancedcustomfields.com');
// --- end temporary block ---

define('DISABLE_WP_CRON', true);        // permanent: system cron runs WP-Cron (step 10)
```

`www.google.com` is allowed because the Contact Us and Franchising forms check reCAPTCHA server-side; without it every form submission fails. Microsoft Dataverse (franchise leads) stays blocked, so the clone can't put test leads into the CRM.

`DISABLE_WP_CRON` also stops the copy's UpdraftPlus, which has production's S3 settings, from uploading backups or pruning production's. Don't press **Backup Now** on the new server before go-live.

Fix ownership and check the site:

```bash
sudo chown -R www-data:www-data /var/www/html/wordpress
sudo find /var/www/html/wordpress -type d -exec chmod 0755 {} +
sudo find /var/www/html/wordpress -type f -exec chmod 0644 {} +
sudo chmod 0640 /var/www/html/wordpress/wp-config.php

cd /var/www/html/wordpress
sudo -u www-data -H wp core verify-checksums
sudo -u www-data -H wp core update-db
sudo -u www-data -H wp plugin list --status=active
sudo -u www-data -H wp option update nginx_cache_path /var/cache/nginx/tc-wordpress
sudo -u www-data -H wp redis enable
sudo -u www-data -H wp redis status
```

- If `verify-checksums` reports modified core files, replace core with a clean copy of the same version: `sudo -u www-data -H wp core download --force --skip-content --version="$(sudo -u www-data -H wp core version)"`.
- `nginx_cache_path` is the Nginx Cache plugin's **Cache Zone Path**. The page cache moved out of the web root to `/var/cache/nginx/tc-wordpress`.

Point the site's git checkout at the new deploy key:

```bash
sudo git config --global --add safe.directory /var/www/html/wordpress
sudo git remote set-url origin github-wordpress:Taco-Cabana-IT-Services/tacocabana.com.git
sudo git fetch origin
sudo git status
```

Expect branch `main` (staging: `dev`) with no changes except the untracked `tc-clone-guard.php`. If it says "behind", don't pull yet; keep the copy like-for-like until go-live.

## 8. New server: turn on nginx

Use the site file from the table:

```bash
sudo rm /etc/nginx/sites-enabled/default
sudo ln -s ../sites-available/catch-all.conf /etc/nginx/sites-enabled/catch-all.conf
sudo ln -s ../sites-available/tacocabana.com.conf /etc/nginx/sites-enabled/tacocabana.com.conf
sudo nginx -t
sudo systemctl reload nginx
```

`catch-all.conf` drops requests for the bare IP or any hostname the server doesn't serve. Site logs are now in `/var/log/nginx/` (rotated automatically), not in the web root.

## 9. Test from your computer only

On Windows, edit `C:\Windows\System32\drivers\etc\hosts` as Administrator and add one line (staging: just `www-staging.tacocabana.com`), then run `ipconfig /flushdns`:

```text
REPLACE_NEW_PUBLIC_IP www.tacocabana.com marketing.tacocabana.com email.tacocabana.com
```

In wp-admin, a yellow **"TC Clone Guard is on"** notice confirms you're on the new server. Your local DDEV crawl covered every page load; these are what it couldn't cover:

- [ ] **Tools → Site Health** shows PHP 8.5. Warnings about blocked HTTP requests and late scheduled events are expected (clone guard, cron off).
- [ ] **Save a big page:** edit the Home page, **Update**, reload the editor, and check every section is still there.
- [ ] **Media:** upload an image and check its thumbnails were created.
- [ ] **Contact Us:** submit it. It should succeed, and `sudo grep TC_CLONE_GUARD /var/log/nginx/tacocabana.com.error.log` should show the email that would have been sent.
- [ ] **Other forms** (catering, donation/benefit nights, contest): same check. On **production**, skip the Franchising form: Dataverse is blocked on purpose, so it fails at that step. Test it right after go-live with a clearly marked test entry.
- [ ] Locations map and search, menu, order links, `/contactus` and `/find-a-tc-location` redirects, `http://` → `https://`.
- [ ] **Page cache:** logged out, run `curl --resolve www.tacocabana.com:443:REPLACE_NEW_PUBLIC_IP -sI https://www.tacocabana.com/menu/` twice from WSL (WSL doesn't read the Windows hosts file). The second time shows `Fastcgi-Cache: HIT`. Logged-in requests show `BYPASS`.
- [ ] **SendGrid (production):** take a tracked link from a recent marketing email and run `curl --resolve marketing.tacocabana.com:443:REPLACE_NEW_PUBLIC_IP -s -o /dev/null -w '%{http_code} %{redirect_url}\n' 'PASTE_LINK'`. Expect `302` and the real destination.
- [ ] **Bare IP is refused:** `curl -sI http://REPLACE_NEW_PUBLIC_IP/` gets an empty reply.
- [ ] **Wordfence → Firewall** shows Extended Protection (its `.user.ini` came with the site; if not, click Optimize). Under **All Options → How does Wordfence get IPs**, choose **REMOTE_ADDR**. The old nginx config never passed the visitor's IP to PHP; the new one does. Check that Live Traffic shows your own IP.
- [ ] **Logs:** `sudo tail -n 50 /var/log/nginx/tacocabana.com.error.log` shows no PHP fatal errors.
- [ ] `sudo reboot`, then recheck the site, `free -h` and `df -h /`.

## 10. Go live

Pick a quiet time. Production is in maintenance mode for roughly 15–30 minutes. Staging can skip steps 1–3 if nobody has edited it since step 6.

1. **Old server**, freeze the site (visitors, forms, the REST API and cron all stop):

   ```bash
   cd /var/www/html/wordpress && sudo -u www-data wp maintenance-mode activate
   ```

2. **WSL**, final copy. Re-run step 6.1 and 6.2 (only changes are sent), then push the files without overwriting the new `wp-config.php`:

   ```bash
   rsync -az --info=progress2 --no-owner --no-group --exclude=/wp-config.php -e "ssh -i $TC_KEY" --rsync-path="sudo rsync" \
     "$TC_WORK/site/" ubuntu@"$TC_NEW":/var/www/html/wordpress/
   ```

3. **New server**, then the WSL import, then the new server again:

   ```bash
   sudo mysql -e "DROP DATABASE tc_wordpress; CREATE DATABASE tc_wordpress CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   ```

   ```bash
   gunzip -c "$TC_WORK/db.sql.gz" | ssh -i "$TC_KEY" ubuntu@"$TC_NEW" "sudo mysql $TC_DB"
   ```

   ```bash
   cd /var/www/html/wordpress
   sudo chown -R www-data:www-data /var/www/html/wordpress
   sudo -u www-data -H wp core update-db
   sudo -u www-data -H wp option update nginx_cache_path /var/cache/nginx/tc-wordpress
   sudo -u www-data -H wp cache flush
   sudo rm -rf /var/cache/nginx/tc-wordpress/*
   ```

   The copied `.maintenance` file keeps the new site in maintenance mode too, until step 7 below.

4. **New server**: `sudoedit wp-config.php` and delete the TEMPORARY block (keep `DISABLE_WP_CRON`). Then remove the guard and start cron:

   ```bash
   sudo rm /var/www/html/wordpress/wp-content/mu-plugins/tc-clone-guard.php
   sudo install -m 0644 /etc/nginx/setup/cron/tc-wordpress /etc/cron.d/tc-wordpress
   ```

5. **EC2 console**, the new instance's security group: add inbound TCP 80 and 443 from `0.0.0.0/0` (and `::/0` if the instance has an IPv6 address). Keep SSH limited to your IP. Staging can stay limited to your IP if only you use it.

6. **EC2 console → Elastic IPs**: select the IP → **Actions → Associate Elastic IP address** → the new instance, tick **Allow this Elastic IP address to be reassociated** → Associate.

7. **New server** (now at the Elastic IP): `cd /var/www/html/wordpress && sudo -u www-data wp maintenance-mode deactivate`.

8. **WSL / Windows**: remove the hosts-file line, run `ipconfig /flushdns`, and run `ssh-keygen -R REPLACE_ELASTIC_IP` (the IP now has a different host key).

9. Check the public site: browse it, send a test email from **FluentSMTP**, submit the Franchising form with a test entry (production), and after 5 minutes run `sudo journalctl -t tc-wp-cron` to check cron ran.

10. **Stop** the old instance; don't terminate it. It's still in maintenance mode, so it can't take changes if it's started by accident.

**Rollback:** start the old instance, associate the Elastic IP back to it, and run `wp maintenance-mode deactivate` there. Anything saved on the new server since go-live (form entries, edits) isn't on the old one.

## 11. After go-live

- In the site repo, update the Deployment and Tech Stack sections of `README.md` and the cache section of `AGENTS.md` with the commands below (they still say `php8.1-fpm` and `/var/www/html/wordpress/cache`).
- **UpdraftPlus**: run **Backup Now** and check that it arrives in S3.
- **EBS snapshots**: EC2 → Lifecycle Manager → create a daily snapshot policy for the new instance's volume (keep 7).
- After about two weeks without problems: create an AMI of the old instance (Actions → Image and templates → Create image) as an archive, then terminate it.

## Deploying changes later

Site code (staging: branch `dev`):

```bash
cd /var/www/html/wordpress
sudo git pull
sudo chown -R www-data:www-data /var/www/html/wordpress
sudo -u www-data -H wp cache flush            # 1. Redis object cache
sudo systemctl reload php8.5-fpm              # 2. OPcache
sudo rm -rf /var/cache/nginx/tc-wordpress/*   # 3. nginx page cache
```

nginx config: edit and commit in this repo on your computer, push, then on the server:

```bash
cd /etc/nginx && sudo git pull && sudo nginx -t && sudo systemctl reload nginx
```

When `apt` asks whether to replace a modified config file (`nginx.conf`, `www.conf`), keep the installed version (the default, `N`). Unattended upgrades keep it automatically.

Certificate renewal (before Apr 23 2027): replace `/etc/ssl/certs/tc.com-2027-fullchain.crt` and `/etc/ssl/private/threeyear.tacocabana.com.key` on both servers, or change the paths in `global/server/ssl.conf`, then run `sudo nginx -t && sudo systemctl reload nginx`. Also update the `order.tacocabana.com` and apex CloudFront distributions.
