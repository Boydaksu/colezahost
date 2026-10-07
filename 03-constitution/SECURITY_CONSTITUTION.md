# Security Constitution

- Default deny for authorization and extension permissions.
- Backend authorization is mandatory; hidden UI is never security.
- Mandatory admin 2FA policy in production; recovery codes protected.
- Step-up authentication for high-risk operations.
- Vault for provider/API/webhook secrets; secrets masked, replace/rotate rather than reveal.
- Passwords, tokens, TOTP secrets, recovery codes, card data, EPP and provider secrets never logged.
- Raw card data never stored/migrated.
- Webhooks require signature/timestamp/event-id validation where supported.
- File uploads use private storage, MIME/extension/size/path checks; unsafe files quarantined/blocked.
- Rate limits for login/reset/API/webhooks.
- Security events and immutable audit are separate from application logs.
- Dependency/secret/security scans are release gates.
