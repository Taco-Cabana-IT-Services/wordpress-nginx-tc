# Taco Cabana WordPress / NGINX

Start with **[STEPS.md](STEPS.md)**. It is the current guide for rebuilding staging and production EC2 instances on Ubuntu 26.04 LTS with PHP 8.5, current WordPress, NGINX, MySQL and Redis. It covers owner-only setup access, HTTPS, migration, testing and traffic cutover.

The guide was consolidated from both supplied 2021 installation guides, the previous server setup notes, current NGINX configuration and the [application README](/home/jpdemko/tacocabana.com/README.md).

## Files to use for the replacement servers

| File | Purpose |
| --- | --- |
| [STEPS.md](STEPS.md) | The single operator guide; staging first, then production |
| [setup/wordpress.nginx.conf](setup/wordpress.nginx.conf) | WordPress/PHP 8.5 site template; optional conservative HTML caching |
| [setup/production-proxies.nginx.conf](setup/production-proxies.nginx.conf) | Production ordering and SendGrid proxy configuration |
| [setup/route53-certbot-policy.json](setup/route53-certbot-policy.json) | Scoped DNS-challenge policy template for the replacement EC2 role |
| [setup/tc-build-guard.php](setup/tc-build-guard.php) | Suppress WordPress email/indexing while testing a non-production clone |

`UPGRADE-PLAN.md` and `MIGRATION-PLAN.md` redirect to the consolidated guide.

## Existing configuration reference

The root `nginx.conf`, `global/`, `sites-available/` and `modules-enabled/` describe the existing servers. Their PHP upstream is **8.1** in `global/php-pool.conf`; individual sites use `$upstream`. They contain older module, TLS, caching and filesystem assumptions.

Keep the new Ubuntu package's NGINX defaults and use the new templates. Review needed custom routes/settings individually rather than replacing the entire `/etc/nginx` directory with this checkout. Dynamic module binaries must match the installed NGINX build.

The separate [SSL automation plan](ssl-automation-plan/README.md) remains a reference for the broader certificate project. The rebuild guide supplies its own Let's Encrypt/Route 53 path that works while inbound access is restricted.
