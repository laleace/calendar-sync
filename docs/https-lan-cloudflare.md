# HTTPS in rete locale con dominio Cloudflare (guida riusabile)

Obiettivo: `https://app.tuodominio.it` raggiungibile **solo dalla LAN**, con certificato Let's Encrypt **valido** (nessun avviso nel browser), senza aprire porte sul router.

## Come funziona (in 3 righe)

1. Su Cloudflare un record `A` fa puntare il nome a un **IP privato** (es. `192.168.1.50`). Da Internet non porta da nessuna parte; in LAN sì.
2. Let's Encrypt emette il certificato con la challenge **DNS-01**: certbot crea un record TXT temporaneo via API Cloudflare. Non serve nessuna porta aperta.
3. nginx serve il sito con quel certificato; certbot lo rinnova da solo.

## Valori da sostituire (per ogni nuovo progetto)

| Segnaposto | Esempio |
|---|---|
| `HOST` | `app.tuodominio.it` |
| `IP` | `192.168.1.50` (IP statico della macchina/container col web server) |

```bash
HOST=app.tuodominio.it
```

---

## Parte A – Una volta sola (valida per tutti i progetti)

### A1. Token API Cloudflare
Cloudflare → **My Profile → API Tokens → Create Token** → modello **Edit zone DNS**.
- Permessi: `Zone → DNS → Edit`
- Zone Resources: solo la tua zona (`tuodominio.it`)

Salvalo sul server (sempre permessi 600):

```bash
apt install -y nginx certbot python3-certbot-dns-cloudflare
mkdir -p /etc/letsencrypt
install -m 600 /dev/null /etc/letsencrypt/cloudflare.ini
echo "dns_cloudflare_api_token = <IL-TOKEN>" > /etc/letsencrypt/cloudflare.ini
```

### A2. Reload automatico di nginx a ogni rinnovo

```bash
mkdir -p /etc/letsencrypt/renewal-hooks/deploy
printf '#!/bin/sh\nsystemctl reload nginx\n' > /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
chmod +x /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
```

(`certbot.timer` rinnova già da solo ogni giorno se serve.)

### A3. Eccezione "DNS rebinding" sul router (solo se serve)
Molti router/DNS locali (FRITZ!Box, Pi-hole, AdGuard, OPNsense) bloccano i nomi pubblici che risolvono su IP privati. Sintomo:

```bash
dig +short $HOST @1.1.1.1   # restituisce l'IP
dig +short $HOST            # vuoto  → è il router
```

Soluzione: aggiungi `HOST` (o tutto `tuodominio.it`) alle eccezioni anti-rebinding, oppure crea un record DNS locale `HOST → IP`. FRITZ!Box: *Rete domestica → Rete → Impostazioni di rete → Protezione dal DNS rebinding*. Poi svuota la cache sul Mac:

```bash
sudo dscacheutil -flushcache; sudo killall -HUP mDNSResponder
```

---

## Parte B – Per ogni nuovo sito (5 minuti)

### B1. DNS su Cloudflare
**DNS → Records → Add record**: tipo `A`, nome `app`, contenuto `IP`, **Proxy OFF** (nuvola grigia, "DNS only"). Il proxy arancione non funziona con IP privati.

### B2. Certificato

```bash
certbot certonly --dns-cloudflare \
  --dns-cloudflare-credentials /etc/letsencrypt/cloudflare.ini \
  --dns-cloudflare-propagation-seconds 30 \
  -d "$HOST"
```

Risultato: `/etc/letsencrypt/live/$HOST/fullchain.pem` e `privkey.pem`.

### B3. Virtual host nginx
Crea `/etc/nginx/sites-available/$HOST` (sostituisci `app.tuodominio.it` e la parte `location`):

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name app.tuodominio.it;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name app.tuodominio.it;

    ssl_certificate     /etc/letsencrypt/live/app.tuodominio.it/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/app.tuodominio.it/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_session_cache   shared:SSL:10m;

    # Solo LAN (difesa in più: nessuna porta è comunque aperta verso Internet)
    allow 127.0.0.1;
    allow ::1;
    allow 10.0.0.0/8;
    allow 172.16.0.0/12;
    allow 192.168.0.0/16;
    deny all;

    # --- scegli UNA delle due varianti ---

    # (1) App che gira su una porta locale (Node, Docker, Python, ...)
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

    # (2) Sito statico
    # root /var/www/mio-sito;
    # index index.html;
    # location / { try_files $uri $uri/ =404; }
}
```

Attivalo:

```bash
ln -s /etc/nginx/sites-available/$HOST /etc/nginx/sites-enabled/$HOST
nginx -t && systemctl reload nginx
```

Per PHP/Laravel vedi `deploy/nginx/calendar-sync.conf` (variante php-fpm).

### B4. L'app deve sapere di essere in HTTPS
Dietro un reverse proxy l'app vede HTTP. Imposta `APP_URL=https://app.tuodominio.it` e fidati degli header `X-Forwarded-*` (in Laravel: `TRUSTED_PROXIES=127.0.0.1`), altrimenti genera link `http://` e il browser blocca gli asset.

---

## Verifica

```bash
dig +short $HOST                     # → IP privato
curl -I https://$HOST                # → 200/302, nessun errore di certificato
certbot certificates                 # → scadenza ~90 giorni
certbot renew --dry-run              # → rinnovo automatico OK
```

## Problemi comuni

| Sintomo | Causa / rimedio |
|---|---|
| `dig` in LAN non restituisce nulla | DNS rebinding del router (A3) |
| certbot: errore token / `Unable to determine zone` | Token senza `Zone → DNS → Edit` o su zona sbagliata |
| certbot: TXT non trovato | Alza `--dns-cloudflare-propagation-seconds` (60–120) |
| Avviso certificato nel browser | Stai usando l'IP invece del nome, oppure nginx non ricaricato |
| 403 da nginx | IP del client fuori dai `allow` |
| Pagina senza stile / mixed content | `X-Forwarded-Proto` non passato o `APP_URL` in http |
| Dall'esterno non si apre | È voluto: l'IP è privato e non ci sono port forward |

## Più siti, un solo certificato (opzione)
Se hai molti sottodomini, puoi emettere un wildcard una volta: `-d "*.tuodominio.it" -d tuodominio.it`, e usare gli stessi `ssl_certificate` in tutti i vhost (percorso `live/tuodominio.it/`). Meno comandi, e in più i nomi dei singoli progetti non finiscono nei log pubblici di Certificate Transparency (con un certificato per sito sì). Contro: una sola chiave privata condivisa da tutti i siti.
