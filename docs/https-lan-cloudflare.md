# HTTPS inside a local network with a Cloudflare domain (reusable guide)

[Italiano](https-lan-cloudflare.it.md)

Goal: `https://app.yourdomain.com` reachable **only from your LAN**, with a **valid** Let's Encrypt certificate (no browser warning), without opening any port on the router.

## How it works (in 3 lines)

1. On Cloudflare, an `A` record points the name to a **private IP** (for example `192.168.1.50`). From the Internet it leads nowhere; on the LAN it works.
2. Let's Encrypt issues the certificate with the **DNS-01** challenge: certbot creates a temporary TXT record through the Cloudflare API. No open port is needed.
3. nginx serves the site with that certificate; certbot renews it by itself.

## Values to replace (for every new project)

| Placeholder | Example |
|---|---|
| `HOST` | `app.yourdomain.com` |
| `IP` | `192.168.1.50` (static IP of the machine or container with the web server) |

```bash
HOST=app.yourdomain.com
```

---

## Part A – Once only (valid for all projects)

### A1. Cloudflare API token
Cloudflare → **My Profile → API Tokens → Create Token** → template **Edit zone DNS**.
- Permissions: `Zone → DNS → Edit`
- Zone Resources: only your zone (`yourdomain.com`)

Save it on the server (always with mode 600):

```bash
apt install -y nginx certbot python3-certbot-dns-cloudflare
mkdir -p /etc/letsencrypt
install -m 600 /dev/null /etc/letsencrypt/cloudflare.ini
echo "dns_cloudflare_api_token = <THE-TOKEN>" > /etc/letsencrypt/cloudflare.ini
```

### A2. Automatic nginx reload at every renewal

```bash
mkdir -p /etc/letsencrypt/renewal-hooks/deploy
printf '#!/bin/sh\nsystemctl reload nginx\n' > /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
chmod +x /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
```

(`certbot.timer` already renews by itself every day when needed.)

### A3. "DNS rebinding" exception on the router (only if needed)
Many routers and local DNS servers (FRITZ!Box, Pi-hole, AdGuard, OPNsense) block public names that resolve to private IPs. Symptom:

```bash
dig +short $HOST @1.1.1.1   # returns the IP
dig +short $HOST            # empty  → it is the router
```

Solution: add `HOST` (or the whole `yourdomain.com`) to the anti-rebinding exceptions, or create a local DNS record `HOST → IP`. FRITZ!Box: *Home Network → Network → Network Settings → DNS rebind protection*. Then flush your computer's DNS cache (macOS command):

```bash
sudo dscacheutil -flushcache; sudo killall -HUP mDNSResponder
```

---

## Part B – For every new site (5 minutes)

### B1. DNS on Cloudflare
**DNS → Records → Add record**: type `A`, name `app`, content `IP`, **Proxy OFF** (grey cloud, "DNS only"). The orange proxy does not work with private IPs.

### B2. Certificate

```bash
certbot certonly --dns-cloudflare \
  --dns-cloudflare-credentials /etc/letsencrypt/cloudflare.ini \
  --dns-cloudflare-propagation-seconds 30 \
  -d "$HOST"
```

Result: `/etc/letsencrypt/live/$HOST/fullchain.pem` and `privkey.pem`.

### B3. nginx virtual host
Create `/etc/nginx/sites-available/$HOST` (replace `app.yourdomain.com` and the `location` part):

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name app.yourdomain.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name app.yourdomain.com;

    ssl_certificate     /etc/letsencrypt/live/app.yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/app.yourdomain.com/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_session_cache   shared:SSL:10m;

    # LAN only (extra safety: no port is open towards the Internet anyway)
    allow 127.0.0.1;
    allow ::1;
    allow 10.0.0.0/8;
    allow 172.16.0.0/12;
    allow 192.168.0.0/16;
    deny all;

    # --- pick ONE of the two variants ---

    # (1) An app listening on a local port (Node, Docker, Python, ...)
    location / {
        proxy_pass http://127.0.0.1:3000;
        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_http_version 1.1;
        proxy_set_header Upgrade    $http_upgrade;   # websocket
        proxy_set_header Connection "upgrade";
    }

    # (2) Static site
    # root /var/www/my-site;
    # index index.html;
    # location / { try_files $uri $uri/ =404; }
}
```

For calendar-sync in Docker, the proxy target is `http://127.0.0.1:8080` (see [docker.md](docker.md)). Enable it:

```bash
ln -s /etc/nginx/sites-available/$HOST /etc/nginx/sites-enabled/$HOST
nginx -t && systemctl reload nginx
```

For PHP/Laravel see `deploy/nginx/calendar-sync.conf` (php-fpm variant).

### B4. The app must know it is on HTTPS
Behind a reverse proxy the app sees HTTP. Set `APP_URL=https://app.yourdomain.com` and trust the `X-Forwarded-*` headers (in Laravel: `TRUSTED_PROXIES=127.0.0.1`), otherwise it generates `http://` links and the browser blocks the assets.

---

## Verification

```bash
dig +short $HOST                     # → private IP
curl -I https://$HOST                # → 200/302, no certificate error
certbot certificates                 # → expiry in ~90 days
certbot renew --dry-run              # → automatic renewal OK
```

## Common problems

| Symptom | Cause / fix |
|---|---|
| `dig` on the LAN returns nothing | Router DNS rebinding protection (A3) |
| certbot: token error / `Unable to determine zone` | Token without `Zone → DNS → Edit`, or on the wrong zone |
| certbot: TXT not found | Raise `--dns-cloudflare-propagation-seconds` (60–120) |
| Certificate warning in the browser | You are using the IP instead of the name, or nginx was not reloaded |
| 403 from nginx | Client IP outside the `allow` list |
| Page without style / mixed content | `X-Forwarded-Proto` not passed, or `APP_URL` is http |
| Does not open from outside | By design: the IP is private and there are no port forwards |

## More sites, one certificate (option)
With many subdomains you can issue a wildcard once: `-d "*.yourdomain.com" -d yourdomain.com`, and use the same `ssl_certificate` in all the virtual hosts (path `live/yourdomain.com/`). Fewer commands, and the names of your individual projects stay out of the public Certificate Transparency logs (with one certificate per site they do not). Downside: a single private key shared by all the sites.
