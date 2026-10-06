# Marketing CRM – mobile app

Expo (React Native + TypeScript + Expo Router) app for the field team. It shows the
same figures as the web dashboard, using the CRM's `/api` endpoints with a sign-in token.

| Screen | What it shows |
|---|---|
| Dashboard | Sales vs target, collection, outstanding 0-90 / 90 DAYS / 150 DAYS, pending orders, samples & DC, email counts |
| Customers | Search; customer detail with open bills (oldest first, coloured by age), recent invoices and receipts, tap to call |
| Leads | Follow-ups due (overdue in red, tap to call) and open leads |
| Account | Who is signed in, their role and data scope; sign out |

Adding entries, imports, SMS and settings stay on the web.

## Security

* The phone stores only a sign-in token, in the secure keychain/keystore (`expo-secure-store`).
* The server checks the token, the user's permissions and data scope on **every** request;
  the app only hides screens. A sales executive sees exactly what they see on the web.
* Sign-out revokes the token on the server. An Admin can also disable the user on the web.
* Users must change their temporary password on the web once before the app can sign in.

## Try it on a phone (same Wi-Fi as the office PC)

1. Install **Expo Go** from the Play Store / App Store.
2. On the office PC:
   ```sh
   cd mobile
   npm install
   npx expo start
   ```
3. Scan the QR code with Expo Go (Android) or the Camera app (iPhone).
4. In the app, server address: `192.168.29.12/marketing_crm` (the address used in the browser on Wi-Fi).

The office PC's firewall must allow Apache on the LAN (`tools/allow-wifi-access.ps1`).

## Phone-browser version (no install)

```sh
cd mobile
npm run build:web
```

This writes the web build to `public/app/`, so any phone on the Wi-Fi can open
`http://192.168.29.12/marketing_crm/app/` and "Add to Home screen". The server address is filled in automatically.
The build folder is not committed to git; run the command again after changing the app.

## Installable Android app (APK)

Built in the cloud with EAS (needs a free Expo account):

```sh
npx eas-cli@latest build --platform android --profile preview
```

**Important:** Android blocks plain `http://` for installed apps. For an installed APK, serve the CRM over
**HTTPS** (recommended for any use outside the office), or add the `expo-build-properties` plugin with
`android.usesCleartextTraffic: true` for an office-only build. Expo Go during testing is not affected.

## Development checks

```sh
npm run typecheck   # TypeScript
npm run lint        # ESLint (eslint-config-expo)
```

API endpoints used: `POST /api/auth/login`, `GET /api/auth/me`, `POST /api/auth/logout`,
`GET /api/dashboard/summary`, `GET /api/customers?q=`, `GET /api/customers/{id}`,
`GET /api/leads?status=open|won|lost|all`, `GET /api/followups/due`.
