# BG-02c4b3 — exact-SHA Preview boot evidence

**Observed at:** 2026-08-25 15:18 WIB (Asia/Jakarta)  
**Boundary:** read-only Vercel Preview verification; synthetic teaching application only  
**Hosted mutation:** none

## Exact deployment identity

| Field | Observed value |
| --- | --- |
| Repository branch | `main` |
| Exact Git SHA | `5129e31d1077dd340feb6af016151cef64f50b2b` |
| Vercel deployment | `dpl_Bj1s8MgwZMjUxGkajt3B1f6whYAk` |
| Deployment URL | `simrs-campus-ueu-demo-deiwrc7zo-danielhappyg.vercel.app` |
| Vercel state | `READY` |
| Vercel target | Preview (`target: null`) |
| Region | `sin1` |

Vercel deployment metadata bound the Preview to the exact SHA above. This Preview is not the public Production alias and was not promoted during this verification.

## Protected runtime proof

The Preview is protected by Vercel Authentication. A temporary Vercel-generated share session was used in the browser; its token is not retained in this artifact.

After the protected navigation completed:

- `/up` rendered the application-owned heading `Application up` and the message `HTTP request received. Response rendered in 53ms.`;
- `/login` rendered the Indonesian title `Masuk - SIMRS Campus UEU`;
- the environment banner rendered `SIMULASI — DATA SINTETIS`;
- the login form rendered Email, Kata sandi, Ingat saya and Masuk controls;
- self-registration was absent and the page stated that accounts are administrator-provided; and
- the UEU wordmark loaded successfully with a non-zero natural width.

The application shell loaded the hashed build assets:

- `build/assets/app-CWDOgPA4.js`;
- `build/assets/login-BCuXnevp.js`;
- `build/assets/app-DXAPbEyw.css`; and
- `build/assets/app-BtG4B5FE.css`.

No Production database credentials were attached to the Preview and no application credentials were entered.

## Evidence limit

This proves protected Preview boot, synthetic-mode labeling, login-shell rendering, application health-page rendering and static asset availability for the exact deployment. It does not prove:

- the Production environment-key configuration;
- Production alias promotion;
- shared maintenance-marker behavior on the Production alias;
- direct HTTP status capture for `/up` through the protected browser session;
- database connectivity or migration state;
- authenticated role workflows; or
- hosted UAT acceptance.

Those items remain inside the controlled BG-02c4b3 cutover and later T0/G3 gates.

