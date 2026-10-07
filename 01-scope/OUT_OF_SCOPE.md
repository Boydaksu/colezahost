# Permanent / Explicit Non-Goals for Initial Architecture

- Microservices are not a target by default.
- Core must not require Docker, Redis, Node runtime, Supervisor or Elasticsearch.
- Core is not a full legal general-ledger/accounting/ERP product.
- Third-party PHP code cannot be falsely claimed to be perfectly OS-sandboxed on shared hosting.
- Raw card data is never stored or migrated.
- AI is never source of truth for finance, fraud adjudication or authorization.
- Themes/templates/modules cannot override Core business/auth logic.
- Migration cannot maintain indefinite bidirectional sync with WHMCS/WiseCP after cutover.
