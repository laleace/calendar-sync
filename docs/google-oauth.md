# Google Cloud Console: credenziali OAuth per calendar-sync

Servono una sola volta. Alla fine avrai tre valori da mettere nel `.env`: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` e `GOOGLE_REDIRECT_URI`.

> La console di Google cambia spesso nomi e posizioni dei menu. Se qualcosa non corrisponde, cerca la voce con la barra di ricerca in alto.

## 1. Progetto e API

1. Apri https://console.cloud.google.com e accedi con il tuo account Google. Va bene quello personale: non deve essere per forza quello dei calendari da sincronizzare.
2. Dal selettore progetti in alto scegli **Nuovo progetto**. Nome: `calendar-sync`. Crea e selezionalo.
3. Vai su **API e servizi → Libreria**, cerca **Google Calendar API** e premi **Abilita**.

## 2. Schermata di consenso (Google Auth Platform)

1. Vai su **API e servizi → Schermata consenso OAuth**. Nelle versioni recenti la sezione si chiama **Google Auth Platform**. Premi **Inizia**.
2. **Informazioni app**: nome `Calendar Sync` ed email di assistenza (la tua).
3. **Pubblico**: **Esterno**.
4. **Dati di contatto**: la tua email. Accetta le norme e premi **Crea**.
5. Vai su **Accesso ai dati → Aggiungi o rimuovi ambiti**. Cerca e seleziona `https://www.googleapis.com/auth/calendar` (Google Calendar API, "See, edit, share, and permanently delete all the calendars…"), poi salva.
   È l'unico ambito che serve: calendar-sync non chiede né email né profilo.

## 3. Client OAuth

1. Vai su **Client → Crea client**, poi scegli **Tipo di applicazione: Applicazione web**. Nome: `calendar-sync`.
2. In **URI di reindirizzamento autorizzati** aggiungi:
   - `http://localhost:8000/oauth/google/callback`, per lo sviluppo in locale;
   - `https://calsync.<tuo-dominio>/oauth/google/callback`, per la produzione (puoi aggiungerlo anche più avanti).

   Gli URI devono coincidere carattere per carattere con `GOOGLE_REDIRECT_URI`: schema, host, porta e path, senza barra finale. Le "origini JavaScript" non servono.
3. Premi **Crea** e copia subito **Client ID** e **Client secret**. Nella console attuale il secret si vede per intero solo in questo momento: se lo perdi, dovrai generarne un altro.

## 4. `.env`

```dotenv
GOOGLE_CLIENT_ID=1234567890-abc...apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=GOCSPX-...
GOOGLE_REDIRECT_URI=http://localhost:8000/oauth/google/callback
```

In produzione `GOOGLE_REDIRECT_URI` diventa `https://calsync.<tuo-dominio>/oauth/google/callback`. Il secret sta solo nel `.env` del server, mai nel repository.

## 5. "Testing" o "In produzione": i 7 giorni

Finché l'app resta in stato **Testing**, Google fa scadere i refresh token dopo **7 giorni**. Il sync si fermerebbe ogni settimana e il pannello mostrerebbe "Token scaduto" con il pulsante "Riconnetti".

Per uso personale conviene pubblicarla:

1. In **Branding** Google può chiedere i link dell'app. Sono informazioni mostrate nella schermata di consenso, che vedi solo tu. Google li esamina soltanto se chiedi la verifica dell'app, che qui non serve. Compila così:
   - **Home page dell'applicazione**: `https://calsync.<tuo-dominio>` (oppure la home del tuo dominio, se ne hai una);
   - **Link alle norme sulla privacy**: una pagina sul tuo dominio, per esempio `https://<tuo-dominio>/privacy`. Basta un testo breve, come quello sotto;
   - **Termini di servizio**: facoltativo, lascia vuoto;
   - **Domini autorizzati**: `<tuo-dominio>`. È obbligatorio per usare il redirect URI di produzione, e i domini dei link sopra devono rientrare qui.

   Nessuno di questi indirizzi deve per forza essere raggiungibile da Internet, e l'app Laravel non va esposta per questo (vedi la sezione "Esposizione in rete").

   Testo minimo per la pagina privacy:

   > Calendar Sync è un'applicazione personale, usata solo dal proprietario del dominio. Accede a Google Calendar esclusivamente per sincronizzare i calendari del proprietario. I dati non vengono condivisi con terzi né usati per altri scopi. I token di accesso sono conservati cifrati e si possono revocare in qualsiasi momento da https://myaccount.google.com/permissions.

2. Vai su **Pubblico** e premi **Pubblica app**, poi conferma. Lo stato diventa **In produzione**.
3. Non serve la verifica di Google: è richiesta solo per superare i 100 utenti. Con l'app non verificata, al momento del consenso Google mostra l'avviso "Google non ha verificato questa app". Premi **Avanzate → Vai a Calendar Sync (non sicura)**: l'app è la tua.

Se preferisci restare in **Testing**, aggiungi i tuoi account Google in **Pubblico → Utenti di test** e metti in conto di dover riconnettere ogni 7 giorni.

Anche in produzione un refresh token può smettere di funzionare in questi casi:
- revochi l'accesso da https://myaccount.google.com/permissions;
- l'account resta inutilizzato per 6 mesi;
- superi i 100 refresh token attivi per lo stesso account e lo stesso client, cioè dopo molte riconnessioni: i più vecchi vengono invalidati.

In tutti questi casi il pannello mostra "Token scaduto" e il pulsante "Riconnetti".

## 6. Esposizione in rete: non serve

Google non contatta mai il server di calendar-sync:
- il ritorno dal consenso (redirect URI) è un redirect eseguito dal **tuo browser**;
- lo scambio del codice e le chiamate alle API sono connessioni **in uscita** dal server verso Google.

Per il redirect URI di produzione Google chiede solo un indirizzo HTTPS su un dominio pubblico, cioè con un dominio vero, non `.lan` né un IP. L'indirizzo non deve essere raggiungibile da Internet. Due configurazioni possibili, dettagliate allo step 9:

- **Solo LAN.** Un record DNS su Cloudflare `calsync.<tuo-dominio>` punta all'IP privato del container, in modalità "DNS only" (nuvola grigia). Il certificato Let's Encrypt si ottiene con la challenge DNS-01 tramite le API di Cloudflare. Da Internet l'indirizzo non porta da nessuna parte.
- **Cloudflare Tunnel + Cloudflare Access.** Utile se vuoi usare il pannello anche fuori casa. Access chiede un login (per esempio un codice alla tua email) prima ancora di arrivare a Laravel.


## 7. Collegare un account

1. Nel pannello vai su **Account → Nuovo**, scegli **Google** e dai un nome all'account. Premi **Salva**.
2. Vieni portato su Google. Scegli l'account del calendario e concedi l'accesso al calendario: se Google mostra le caselle dei permessi, lascia spuntata quella del calendario.
3. Torni sul pannello, dove l'account risulta collegato e i calendari vengono scoperti automaticamente.
