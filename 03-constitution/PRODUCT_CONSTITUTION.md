# Product Constitution

1. V1 is a production hosting commerce & operations platform, not a demo and not an ERP/CMS/live-chat super-app.
2. Security, correctness, recoverability, shared-host compatibility and performance outrank feature count.
3. Breadth is intentionally constrained: one excellent V1 cPanel adapter, one registrar adapter, Manual/Bank + iyzico, SMTP.
4. `V1-FOUNDATION` never authorizes implementing the future feature itself.
5. New domain/feature scope after lock requires Change Request, impact analysis and explicit approval.
6. Business semantics must be explainable and consistent across UI/API/docs/analytics.
7. Every critical user flow must have failure-path and recovery behavior, not only happy path.
