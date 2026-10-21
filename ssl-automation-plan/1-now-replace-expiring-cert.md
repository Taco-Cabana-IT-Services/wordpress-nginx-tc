# Phase 0: Replace the certificate expiring Nov 7 2026

**Deadline:** before **Nov 7 2026, 16:20 UTC** (8:20 AM Pacific / 10:20 AM Central)
**Time needed:** about 1 hour

## Background

| | Expiring cert | Replacement |
|---|---|---|
| Serial | `22F646A50A43DB93` | `E221F72994A445A0` |
| Valid | Oct 6 2025 → **Nov 7 2026** | Oct 7 2026 → **Apr 23 2027** |
| Covers | `*.tacocabana.com`, `tacocabana.com` | same |
| GoDaddy subscription | 12/5/2022 – 12/5/2028 | same |
| Private key | `threeyear.tacocabana.com.key` | **same key**, so there's no re-key |

Both certs use the same private key and are valid right now. You can update each place below **independently, in any order**. There is no need to coordinate CloudFront and nginx.

**Where the expiring cert was found (scan on Oct 7 2026):**

| Hostname | Served by |
|---|---|
| `www.tacocabana.com`, `marketing.tacocabana.com`, `email.tacocabana.com` | Prod nginx on EC2 (`18.219.223.212`) |
| `tacocabana.com`, `order.tacocabana.com` | CloudFront |
| Unknown internal or Windows systems | Sys admin to check. See [file 5](5-inventory-and-monitoring.md). |

---

## A. CloudFront (AWS console)

We already imported the GoDaddy replacement into ACM. You can choose either cert for CloudFront:

- **A1 (recommended): request a free ACM certificate.** Same effort as switching to the import, and AWS renews it automatically. CloudFront never needs this done again.
- **A2: select the imported GoDaddy cert.** This works, but it has to be redone by hand before Apr 23 2027.

### A1. Request a free ACM certificate (recommended)

1. In the AWS console, set the region selector (top right) to **US East (N. Virginia) us-east-1**. CloudFront can only use certificates from this region.
2. Go to **Certificate Manager** → **Request** → **Request a public certificate** → **Next**.
3. **Fully qualified domain name:** `tacocabana.com`
   Click **Add another name to this certificate:** `*.tacocabana.com`
4. **Validation method:** DNS validation. **Key algorithm:** RSA 2048. Click **Request**.
5. Open the new certificate (status *Pending validation*). In the **Domains** section, click **Create records in Route 53**, keep both names checked, and click **Create records**.
6. Refresh after a few minutes until **Status = Issued**. This usually takes under 30 minutes.
   > **Never delete the `_xxxx.tacocabana.com` CNAME records this creates in Route 53.** ACM uses them to renew the certificate automatically.
7. Go to **CloudFront** → **Distributions**. For **each** distribution whose **Alternate domain names** include a `tacocabana.com` name:
   1. Open it. On the **General** tab, find the **Settings** panel and click **Edit**.
   2. Under **Custom SSL certificate**, pick the new certificate. Match the ID shown in the dropdown with the certificate ID in ACM.
   3. Click **Save changes**.
8. Wait until **Last modified** shows a date instead of *Deploying*. This takes about 5–15 minutes.
9. Verify (see section D).

### A2. Use the imported GoDaddy cert instead

Follow step 7 above, but choose the **imported** certificate (expires Apr 23 2027). Add a calendar reminder for early April 2027.

> The old imported certificate stays in ACM. Once no distribution uses it (the certificate's **Associated resources** list is empty), you can delete it after Nov 7.

---

## B. Prod web server: nginx on EC2

You'll need:
- SSH access to prod (`18.219.223.212`).
- A file-transfer tool such as WinSCP or FileZilla (SFTP), or the upload feature of your SSH client.
- The file `tc.com-2027-fullchain.crt`, from the `certs/future cert on server/` folder on the web admin's PC. It contains only public certificates, so it's safe to copy.

The private key is already on the server and doesn't change.

1. **Upload** `tc.com-2027-fullchain.crt` to `/tmp/` on the server.
2. **Move it into place and check it.** In SSH:
   ```bash
   sudo mv /tmp/tc.com-2027-fullchain.crt /etc/ssl/certs/
   sudo chown root:root /etc/ssl/certs/tc.com-2027-fullchain.crt
   sudo chmod 644 /etc/ssl/certs/tc.com-2027-fullchain.crt
   openssl x509 -in /etc/ssl/certs/tc.com-2027-fullchain.crt -noout -serial -enddate
   ```
   Expected output: `serial=E221F72994A445A0` and `notAfter=Apr 23 16:26:44 2027 GMT`
3. **Back up the site config, then point it at the new file:**
   ```bash
   sudo cp /etc/nginx/sites-available/tacocabana.com.conf ~/tacocabana.com.conf.bak-$(date +%F)
   sudo sed -i 's#tc.com-2026-fullchain.crt#tc.com-2027-fullchain.crt#' /etc/nginx/sites-available/tacocabana.com.conf
   grep -n ssl_certificate /etc/nginx/sites-available/tacocabana.com.conf
   ```
   You should see **two** `ssl_certificate ... tc.com-2027-fullchain.crt` lines. The `ssl_certificate_key` lines stay as they are.
   The copy of `sites-available/tacocabana.com.conf` in this repo already has the change.
4. **Test, then reload.** A reload doesn't drop visitors.
   ```bash
   sudo nginx -t
   sudo systemctl reload nginx
   ```
   Only reload if `nginx -t` says **syntax is ok** and **test is successful**.
5. Verify (see section D).

**Rollback:** `sudo cp ~/tacocabana.com.conf.bak-<date> /etc/nginx/sites-available/tacocabana.com.conf && sudo nginx -t && sudo systemctl reload nginx`

---

## C. Other systems using the wildcard

The sys admin goes through the inventory in [file 5](5-inventory-and-monitoring.md). Any system that has the GoDaddy wildcard installed needs the new one before Nov 7:

- **Windows / IIS / RDP / appliances:** use `tacocabana.com-2027-fullchain.pfx`. Send the file and its password **separately**, through a secure channel such as the password manager or a secure share, never in the same email.
- **Linux / Apache / nginx:** use `tc.com-2027-fullchain.crt` plus the existing private key.

---

## D. Verify

From a PC **without HTTPS filtering**. AdGuard, corporate proxies and some antivirus software swap in their own certificate, so pause them first.

1. Open each site in a browser, click the padlock icon, and view the certificate:
   - `https://www.tacocabana.com`
   - `https://marketing.tacocabana.com`
   - `https://email.tacocabana.com`
   - `https://tacocabana.com`
   - `https://order.tacocabana.com`
2. Check that the expiry date is **after Nov 7 2026**:
   - Apr 23 2027 for the GoDaddy cert.
   - A later date, issued by **Amazon**, if you used option A1 on CloudFront.
3. Optional: run [SSL Labs](https://www.ssllabs.com/ssltest/) on `www.tacocabana.com` to check the chain is complete. It should show no "chain issues".

---

## E. GoDaddy account cleanup (today)

- On the **1/6/2024 – 1/6/2027** subscription (serial `1958A71DF543ACCA`), **turn off auto-renew**.
- **Do not revoke it** until the inventory in [file 5](5-inventory-and-monitoring.md) confirms nothing uses it. Revoking breaks anything still using it immediately. If nothing turns up, it expires on its own on Jan 7 2027.
- **Keep the 2022–2028 subscription for now.** It's our fallback until automation is live everywhere.

## Checklist

- [ ] CloudFront: every `tacocabana.com` distribution switched to the new cert (A1 or A2)
- [ ] Prod nginx: new fullchain installed, config updated, reloaded
- [ ] All five public hostnames verified (section D)
- [ ] Sys admin confirmed internal systems updated, or that none use it
- [ ] 2024–2027 GoDaddy subscription auto-renew turned off
- [ ] Cert files (especially the `.key` and `.pfx`) removed from OneDrive and personal PCs once deployed
