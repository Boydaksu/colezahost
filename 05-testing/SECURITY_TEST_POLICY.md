# Security Test Policy

Stable/phase gates require applicable tests for Authentication, Authorization, CSRF, XSS, SQLi, SSRF, path/ZIP traversal, IDOR/BOLA, file uploads, webhook replay/signatures, rate limits, token scopes, 2FA bypass, impersonation restrictions, secret leakage, module permission boundaries and privacy exports.

Critical security invariant tests are locked. Security findings cannot be suppressed without documented risk acceptance + expiry + approval; Critical/High release blockers are not accepted by AI alone.
