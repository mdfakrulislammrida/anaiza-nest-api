# Sign in with Google and Facebook

## The flow

1. The storefront button (`/account`) opens `GET {API}/api/auth/google/redirect` in the browser.
2. `SocialAuthController` checks the provider is set up and on, sets a short-lived state cookie, and redirects to Google with `redirect_uri`
   set to the callback address (below).
3. Google sends the browser back to `GET {API}/api/auth/google/callback`. The state is checked, the code is traded for the person
   (with the same `redirect_uri`), and a customer is found by email or made (and linked to the existing account when the email is already there).
   An email Google says is not verified is refused.
4. The browser is sent to `{FRONTEND_URL}/auth/callback?code=...` with a one-time code (never the token). The storefront posts it to
   `POST /api/auth/social/exchange` and receives the Sanctum token.

## The address to register (it is never typed anywhere)

`APP_URL` (the API's own public https address) + the path of the named route `social.google.callback`. Admin > Settings > Integrations shows it
read only with a Copy button. There is no `GOOGLE_REDIRECT_URI` setting any more: a missing or mistyped one is what made Google answer
"Missing required parameter: redirect_uri" (or `redirect_uri_mismatch`).

| Where | Authorised redirect URI |
| --- | --- |
| Staging and live (the storefront on new.anaizanest.com or anaizanest.com, the API on api.anaizanest.com) | `https://api.anaizanest.com/api/auth/google/callback` |
| Local (`php artisan serve --port=8787`, APP_URL=http://localhost:8787) | `http://localhost:8787/api/auth/google/callback` |

"Authorised JavaScript origins": none needed. The sign-in is a redirect from the server, not a Google button on the page.

Facebook, the same way: `https://api.anaizanest.com/api/auth/facebook/callback` under Valid OAuth Redirect URIs.

`APP_URL` in the server's `.env` must be `https://api.anaizanest.com`. If it is anything else (localhost, http, another name) the Integrations page says so in red,
because the address shown, and sent to Google, is built from it.

## What the visitor sees

- No client ID or secret yet: the button is hidden (the public site settings say `social_login.google: false`), and the sign-in address answers
  "Google login is not set up yet" (JSON) or sends a browser back to the storefront's sign-in page with a calm message. It never reaches Google's error page.
- Set up but switched off: hidden the same way, with "not available just now".
- The client secret, the Facebook app secret and the SMTP password are write-only: encrypted, never shown again, "Saved (ends in ••••1234)" under the field,
  blank on save keeps the saved one, a trash icon clears one after a confirm. No public response contains them.
