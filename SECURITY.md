# Security Policy

## Current product boundary

This repository is for a university hospital simulation and teaching platform. It is not authorized for live clinical care.

- Use synthetic patients only.
- Do not commit patient information, student assessment exports, production credentials, private keys, access tokens, or server configuration containing secrets.
- Do not connect a production SATUSEHAT, BPJS, payment, laboratory, or other clinical endpoint without a separately approved integration and governance review.
- Every deployed teaching environment must display its simulation status clearly.

## Reporting a vulnerability

Do not disclose a suspected vulnerability in a public issue. Contact the repository owner privately with:

- the affected component and environment;
- reproducible steps;
- the likely impact;
- any evidence, with personal data and secrets removed;
- a safe remediation suggestion, if known.

The repository owner will acknowledge the report, assess containment, and coordinate remediation before public disclosure.

## If sensitive data is discovered

Stop processing it, do not copy it into an issue or chat, preserve relevant audit evidence, revoke exposed credentials, restrict the affected environment, and begin the university’s privacy/security incident process.

