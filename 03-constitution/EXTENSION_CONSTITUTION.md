# Extension Constitution

- Strict manifest: ID/version/type/Core compatibility/extension API/dependencies/permissions/capabilities.
- Install: quarantine → validate → path/symlink/zip-bomb scan → compatibility → permissions → migration preview → health → enable.
- Third-party routes, tables, files and settings are namespaced.
- Core route/auth/session/business logic override is forbidden.
- Direct Core table SQL/migration modification is forbidden.
- External network access is declared permission; secrets obtained only via scoped Vault/contract.
- Module disable != uninstall; data retention is explicit.
- Module license cannot lock Core, prevent uninstall/export/erasure or other modules.
- Official modules should use public extension APIs; verified adapters pass contract suites.
- Same-process PHP on shared hosting is not falsely marketed as perfect OS sandboxing.
