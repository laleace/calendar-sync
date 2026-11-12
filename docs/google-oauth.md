# Google Cloud Console: OAuth credentials for calendar-sync

[Italiano](google-oauth.it.md)

You need this once. At the end you will have three values for your `.env`: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` and `GOOGLE_REDIRECT_URI`.

Everyone who installs calendar-sync creates their own OAuth client: there is no shared app. It is free, and for personal use Google does not review anything.

> Google's console often renames and moves its menus. If something does not match, look the item up with the search bar at the top.

## 1. Project and API

1. Open <https://console.cloud.google.com> and sign in with your Google account. A personal one is fine: it does not have to be the account of the calendars you want to sync.
2. In the project selector at the top choose **New project**. Name: `calendar-sync`. Create it and select it.
3. Go to **APIs & Services → Library**, search for **Google Calendar API** and press **Enable**.

## 2. Consent screen (Google Auth Platform)

1. Go to **APIs & Services → OAuth consent screen**. In recent versions the section is called **Google Auth Platform**. Press **Get started**.
2. **App information**: name `Calendar Sync` and a support email (yours).
3. **Audience**: **External**.
4. **Contact information**: your email. Accept the policy and press **Create**.
5. **Data access (scopes): you can skip it.** The list of declared scopes only matters to Google for app verification. calendar-sync asks for the one scope it needs, `https://www.googleapis.com/auth/calendar`, when you connect an account, and Google shows it on the consent screen. If you add it here, Google warns that a "sensitive" scope requires verification: it is a notice, not a block (see section 5).

## 3. OAuth client

1. Go to **Clients → Create client**, then choose **Application type: Web application**. Name: `calendar-sync`.
2. Under **Authorized redirect URIs** add:
   - the address of your installation followed by `/oauth/google/callback`, for example `https://calsync.example.com/oauth/google/callback`: it is your `APP_URL`;
   - `http://localhost:8000/oauth/google/callback`, if you also run it locally for development.

   The URIs must match `GOOGLE_REDIRECT_URI` character by character: scheme, host, port and path, no trailing slash. The "JavaScript origins" are not needed.
3. Press **Create** and copy **Client ID** and **Client secret** right away. In the current console the secret is shown in full only at this moment: if you lose it, you will have to generate another.

## 4. `.env`

```dotenv
GOOGLE_CLIENT_ID=1234567890-abc...apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=GOCSPX-...
GOOGLE_REDIRECT_URI=https://calsync.example.com/oauth/google/callback
```

`GOOGLE_REDIRECT_URI` is optional: when it is not set, it defaults to `APP_URL/oauth/google/callback` (the Docker `.env` leaves it out). The secret lives only in the `.env` of the server, never in the repository.

## 5. "Testing" or "In production": the 7 days

While the app stays in **Testing** status, Google expires the refresh tokens after **7 days**. The sync would stop every week and the panel would show "Token expired" with a "Reconnect" button.

For personal use it is better to publish it:

1. In **Branding** Google may ask for the app's links. They are shown on the consent screen, which only you see. Google reviews them only if you ask for app verification, which you do not need here. Fill them in like this:
   - **Application home page**: `https://calsync.example.com` (or the home page of your domain, if you have one);
   - **Privacy policy link**: `https://calsync.example.com/privacy`. The page is part of the app and public, with no login;
   - **Terms of service**: optional, leave it empty;
   - **Authorized domains**: `example.com`. It is required to use the production redirect URI, and the domains of the links above must be listed here.

   None of these addresses has to be reachable from the Internet, and the app does not have to be exposed for this (see "Network exposure").

2. Go to **Audience** and press **Publish app**, then confirm. The status becomes **In production**.
3. Ignore the verification requests (buttons like "Prepare for verification" or "Submit for verification"). An unverified app in production that uses a sensitive scope works all the same, with two limits that do not matter for personal use:
   - at consent time Google shows "Google hasn't verified this app": press **Advanced → Go to Calendar Sync (unsafe)**, it is your own app;
   - at most 100 users.

   Verification only removes the notice. The Calendar scope is "sensitive", not "restricted", so it does not require a paid security assessment, but it still needs a review with a demo video. It is not worth it for a self-hosted app.

   If the console does not let you publish without verification: with a domain on Google Workspace you can choose the **Internal** audience, which needs no verification and does not expire tokens, but works only for accounts of that Workspace. Otherwise stay in **Testing** and reconnect every 7 days.

If you prefer to stay in **Testing**, add your Google accounts under **Audience → Test users** and expect to reconnect every 7 days.

Even in production a refresh token can stop working when:
- you revoke access at <https://myaccount.google.com/permissions>;
- the account is unused for 6 months;
- you exceed 100 active refresh tokens for the same account and the same client, that is after many reconnections: the oldest are invalidated.

In all these cases the panel shows "Token expired" and the "Reconnect" button.

## 6. Network exposure: not needed

Google never contacts the calendar-sync server:
- the return from the consent screen (redirect URI) is a redirect performed by **your browser**;
- the code exchange and the API calls are **outbound** connections from the server to Google.

For the production redirect URI Google only asks for an HTTPS address on a public domain, that is a real domain, not `.lan` nor an IP. The address does not have to be reachable from the Internet. Two possible setups:

- **LAN only.** A Cloudflare DNS record `calsync.example.com` points to the private IP of your server, in "DNS only" mode (grey cloud). The Let's Encrypt certificate is obtained with the DNS-01 challenge through the Cloudflare API. From the Internet the address leads nowhere. See [https-lan-cloudflare.md](https-lan-cloudflare.md).
- **Cloudflare Tunnel + Cloudflare Access.** Useful if you also want to use the panel away from home. Access asks for a login (for example a code sent to your email) before reaching Laravel at all.

An installation on `localhost` (for example Docker on your own computer, at <http://localhost:8080>) works too: Google accepts `http://localhost` redirect URIs.

## 7. Connect an account

1. In the panel go to **Accounts → New**, choose **Google** and give the account a name. Press **Save**.
2. You are taken to Google. Choose the account of the calendar and grant access to the calendar: if Google shows the permission checkboxes, leave the calendar one ticked.
3. You come back to the panel, where the account is connected and its calendars are discovered automatically.

## 8. Calendars of another Google account

The OAuth client identifies the app, not an account: any Google account can authorize it, up to 100 for an unverified app.

- **A different Google account:** create a new Google account in the panel. On Google choose the other account from the list, or "Use another account". For this account too you will see "Google hasn't verified this app".
- **Alternatively, sharing:** from the other account share the calendar with the one already connected, with the "Make changes to events" permission if it is going to be a target. The calendar will show up among those of the existing account after "Discover calendars".
