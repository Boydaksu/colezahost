# Forbidden Actions Quick List

- `markTestSkipped`, ignore/disable test suite without approved exception.
- Failing test delete/rename so runner cannot discover it.
- Assertion loosen (`===`→truthy, exact amount→non-null etc.) without spec change.
- Catch-and-ignore exception, `@` suppression, generic success fallback to hide failures.
- Fake provider success fixture used as production integration proof.
- `TODO` as placeholder for V1-MUST behavior at phase completion.
- Direct DB writes from controllers/themes/modules to bypass application commands.
- Secret/PII dump in debug/evidence.
- CI config edits whose only effect is making red build green by reducing checks.
- Silent migration skip/data drop.
- Production update without verified backup.
