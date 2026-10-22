# Taco Cabana WordPress / NGINX

nginx configuration for the production (`www.tacocabana.com`) and staging (`www-staging.tacocabana.com`) WordPress servers: Ubuntu 26.04, nginx 1.28, PHP 8.5-FPM, MySQL 8.4, Redis.

**This repo is checked out directly into `/etc/nginx` on each server.** Files Ubuntu's nginx package owns (`mime.types`, `fastcgi.conf`, `snippets/`, `modules-enabled/`, ...) stay untracked so `apt` can keep updating them; see [.gitignore](.gitignore).

To build a new server or move to a new one, follow **[STEPS.md](STEPS.md)**.

## Files

| File | Purpose |
| --- | --- |
| [nginx.conf](nginx.conf) | Main config: FastCGI buffers, page cache zone, includes |
| [global/](global/) | Shared http settings (gzip, limits, PHP 8.5 upstream) |
| [global/server/](global/server/) | Per-site includes: certificate/TLS, page cache rules, security headers, static file caching, blocked paths |
| [sites-available/tacocabana.com.conf](sites-available/tacocabana.com.conf) | Production: www, apex redirect, SendGrid link-branding proxy (`marketing.`, `email.`) |
| [sites-available/www-staging.tacocabana.com.conf](sites-available/www-staging.tacocabana.com.conf) | Staging (noindex) |
| [sites-available/catch-all.conf](sites-available/catch-all.conf) | Default server that drops requests for unknown hostnames and the bare IP |
| [setup/](setup/) | PHP, PHP-FPM pool, MySQL and cron settings that STEPS.md copies into place, plus the temporary clone-guard mu-plugin |

Enable a site with `sudo ln -s ../sites-available/NAME.conf /etc/nginx/sites-enabled/`. Each server enables `catch-all.conf` plus its own site file.

## Making changes

Edit and commit here, push, then on the server:

```bash
cd /etc/nginx && sudo git pull && sudo nginx -t && sudo systemctl reload nginx
```

The old Ubuntu 20.04 / PHP 8.1 configuration is tagged `ubuntu-20.04`. Don't `git pull` this repo on the old servers.

The [SSL automation plan](ssl-automation-plan/README.md) is a separate, later project. Until then, both servers use the GoDaddy wildcard certificate set in [global/server/ssl.conf](global/server/ssl.conf).
