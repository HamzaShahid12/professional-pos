# Security notes

Built in: business and branch isolation on every query, permission checks on every route (checked by `Phase10Test`), server-side totals, immutable financial ledgers, formula protection on CSV and XLSX exports, login lockout, security headers, API throttling.

Not done: two-factor login, a Content-Security-Policy, an external security audit, penetration testing, dependency scanning. Do these before taking real money. Run `composer audit` and `npm audit` regularly.
