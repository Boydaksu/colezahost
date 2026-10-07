# Localization / I18n Contract

- System default `tr_TR`; second official language `en_US`.
- TR and EN key parity must be 100% at stable release; missing/mismatched placeholder is CI failure.
- Namespaced resource files for Core/modules/themes.
- No hard-coded user-visible strings in production code.
- Locale, currency and timezone are independent concepts.
- User → Organization → Brand → System locale resolution.
- Recipient locale controls notifications/documents, not admin session locale.
- Localized product/group descriptions supported.
- ICU-like pluralization/number/date/currency formatting; RTL-friendly CSS logical properties foundation.
- Translation override layers never require editing Core language files.
