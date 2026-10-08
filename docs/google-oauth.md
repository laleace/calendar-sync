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
5. **Accesso ai dati (ambiti): puoi saltarlo.** L'elenco degli ambiti dichiarati serve a Google solo per la verifica dell'app. calendar-sync chiede comunque l'unico ambito che gli serve, `https://www.googleapis.com/auth/calendar`, al momento del collegamento, e Google lo mostra nella schermata di consenso. Se lo aggiungi, Google segnala che un ambito "sensibile" richiede la verifica: è un avviso, non un blocco (vedi la sezione 5).

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
   - **Link alle norme sulla privacy**: `https://calsync.<tuo-dominio>/privacy`. La pagina è inclusa nell'app ed è pubblica, senza login;
   - **Termini di servizio**: facoltativo, lascia vuoto;
   - **Domini autorizzati**: `<tuo-dominio>`. È obbligatorio per usare il redirect URI di produzione, e i domini dei link sopra devono rientrare qui.

   Nessuno di questi indirizzi deve per forza essere raggiungibile da Internet, e l'app Laravel non va esposta per questo (vedi la sezione "Esposizione in rete").

2. Vai su **Pubblico** e premi **Pubblica app**, poi conferma. Lo stato diventa **In produzione**.
3. Ignora le richieste di verifica (pulsanti tipo "Prepara per la verifica" o "Invia per la verifica"). Un'app in produzione non verificata che usa un ambito sensibile funziona lo stesso, con due limiti che per uso personale non contano:
   - al momento del consenso Google mostra l'avviso "Google non ha verificato questa app": premi **Avanzate → Vai a Calendar Sync (non sicura)**, l'app è la tua;
   - massimo 100 utenti.

   La verifica serve solo a togliere l'avviso, e per l'accesso al calendario richiede una revisione completa (video dimostrativo e altro). Non ne vale la pena.

   Se la console non ti lascia pubblicare senza verifica: con un dominio su Google Workspace puoi scegliere il pubblico **Interno**, che non richiede verifica e non fa scadere i token, ma funziona solo per gli account di quel Workspace. Altrimenti resta in **Testing** e riconnetti ogni 7 giorni.

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

## 8. Calendari di un altro account Google

Il client OAuth identifica l'app, non un account: qualsiasi account Google può autorizzarla, fino a 100 per un'app non verificata.

- **Account Google diverso:** crea un nuovo account di tipo Google nel pannello. Su Google scegli l'altro account dalla lista, oppure "Usa un altro account". Anche per questo account comparirà l'avviso "Google non ha verificato questa app".
- **In alternativa, condivisione:** dall'altro account condividi il calendario con quello già collegato, con il permesso "Apporta modifiche agli eventi" se deve fare da destinazione. Il calendario comparirà tra quelli dell'account esistente dopo "Scopri calendari".
