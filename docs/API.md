# API v1

Base `/api/v1`. Authenticate with a Sanctum bearer token from `POST /api/v1/auth/login`. All money is a decimal string. Every request is limited to the token's business and the user's branches.

Phase 10 adds:

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/reports` | `reports.view` | List of the ten reports |
| GET | `/reports/{type}` | `reports.view` | Query `from`, `to` (YYYY-MM-DD, at most 400 days), `branch_id`. Returns `title, columns, rows, totals, chart`. All rows, no cap |

Errors: 401 not signed in, 403 missing permission, 404 unknown report, 422 bad dates. See the README for the earlier endpoints.
