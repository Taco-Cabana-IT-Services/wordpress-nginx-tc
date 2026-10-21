# Inventory, monitoring, and retiring GoDaddy

## Certificate inventory

Keep this table current. **Every system serving HTTPS for a `tacocabana.com` name belongs here.**

The first rows come from a scan on Oct 7 2026. The sys admin needs to add internal systems.

| Hostname(s) | Where it's served | Cert on Oct 7 2026 | Target method | Owner | Status |
|---|---|---|---|---|---|
| `www.tacocabana.com`, `marketing.`, `email.` (+ `*.marketing.`?) | Prod nginx, EC2 `18.219.223.212` | GoDaddy `22F646A50A43DB93`, exp **Nov 7 2026** | certbot → ACM ACME ([file 3](3-linux-nginx-certbot.md) part B) | Web admin | Phase 0 → 3 |
| `tacocabana.com` | CloudFront | GoDaddy `22F646A50A43DB93` (imported to ACM) | ACM requested cert ([file 1](1-now-replace-expiring-cert.md) A1) | Web admin | Phase 0 |
| `order.tacocabana.com` | CloudFront | GoDaddy `22F646A50A43DB93` (imported to ACM) | ACM requested cert ([file 1](1-now-replace-expiring-cert.md) A1) | Web admin | Phase 0 |
| `www-staging.tacocabana.com` | Staging nginx, EC2 `18.116.129.11` | Let's Encrypt, exp Dec 26 2026, auto-renewing | certbot → ACM ACME ([file 3](3-linux-nginx-certbot.md) part A) | Web admin | Phase 2 test |
| `orders.tacocabana.com` | Lunchbox (vendor) | Let's Encrypt, vendor-managed | No action | Vendor | ✅ |
| `webmail.tacocabana.com` | Microsoft 365 | DigiCert, Microsoft-managed | No action | Microsoft | ✅ |
| `catering.tacocabana.com` | Third-party host | DigiCert, vendor-managed | No action | Vendor | ✅ |
| *Internal systems: to be filled in by sys admin* | | | | | |

### Finding systems that use the GoDaddy wildcard

GoDaddy wildcard serials to look for:

| Serial | Subscription | Expires |
|---|---|---|
| `22F646A50A43DB93` | 2022–2028 | Nov 7 2026 |
| `E221F72994A445A0` | 2022–2028, Oct 2026 reissue | Apr 23 2027 |
| `1958A71DF543ACCA` | 2024–2027 (redundant) | Jan 7 2027 |
| `5D06A5F7A1F1486E` | 2024–2027, previous cert | Feb 8 2026 (already expired) |

Where to look:
- **Windows servers:** run `certlm.msc` → **Personal** → **Certificates**, and look for `*.tacocabana.com`. Open it, go to the **Details** tab, and check **Serial number**.
- **Appliances:** firewall, VPN (SSL-VPN portal), load balancers, Wi-Fi controller / captive portal, mail relays, NAS, printers / print servers, RD Gateway, MDM, and anything with a web admin page at a `tacocabana.com` name.
- **People:** ask who has received a `.pfx` or `.key` file in the past few years, by email, OneDrive, Teams, etc.
- **Expired:** anything still showing `5D06A5F7A1F1486E` is already broken, or isn't used by anyone.

> **Note:** DNS has a wildcard record, so made-up names like `vpn.`, `sso.` and `login.tacocabana.com` all point to CloudFront and fail with a certificate error. A name resolving doesn't mean a system exists there. Confirm in Route 53 → Hosted zones → `tacocabana.com` → Records.

---

## Monitoring

Automation fails quietly, so we watch for it from three directions:

| What | Catches | Setup |
|---|---|---|
| CloudWatch alarm `acme-issuance-failures` | AWS rejected a certificate request | [File 2, Step 5](2-aws-acme-endpoint-setup.md#step-5-alert-on-failed-issuance) |
| **External expiry monitor** on every public hostname | Any site serving a cert that's close to expiring, whatever the cause (server off, renewal task broken, wrong cert installed) | Any uptime-monitoring service with SSL-expiry checks. Alert when **under 14 days** remain, to the IT distribution list. |
| ACM console → **Certificates** dashboard | Overview of expiry dates for ACM-issued and ACME-issued certificates | Nothing to set up. Check it monthly. |

The external expiry monitor is the most important. It's the only one that catches a server that **silently stopped trying** to renew.

**Until everything is automated**, keep these on the team calendar:

| Date | Event |
|---|---|
| Nov 7 2026 | Current GoDaddy cert expires (Phase 0 deadline) |
| Nov 9 2026 | **Domain registration** `tacocabana.com` expires at GoDaddy. Not a certificate, but confirm auto-renew is on. |
| Jan 7 2027 | 2024–2027 GoDaddy cert expires. Anything still using it breaks. |
| Mar 15 2027 | Maximum certificate lifetime drops to 100 days |
| Apr 23 2027 | GoDaddy reissue expires. Everything must be automated, or reinstalled by hand. |
| Mar 15 2029 | Maximum certificate lifetime drops to 47 days |

---

## Retiring GoDaddy (Phase 5)

1. **Now:** turn off auto-renew on the **1/6/2024 – 1/6/2027** subscription.
2. **After the inventory is complete:** if nothing uses `1958A71DF543ACCA`, let it expire on Jan 7 2027. Revoking it isn't necessary.
3. **When every inventory row is ✅ automated (target: March 2027):**
   1. Cancel the **12/5/2022 – 12/5/2028** subscription and ask GoDaddy about a prorated refund for the unused term.
   2. After Apr 23 2027, delete leftover GoDaddy files: `/etc/ssl/certs/tc.com-20*-fullchain.crt` and `/etc/ssl/private/threeyear.tacocabana.com.key` on prod, imported certificates in ACM, and copies on PCs, OneDrive and file shares.
4. **Delete every copy of old private keys and `.pfx` files** that were emailed or shared. Each one can be used to impersonate our sites until that certificate expires.
