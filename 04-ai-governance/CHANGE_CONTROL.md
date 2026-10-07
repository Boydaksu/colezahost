# Change Control

Locked spec/architecture/scope değişikliği için zorunlu sıra:
Change Request → Impact Analysis → ADR (mimariyse) → Scope/dependency/test etkisi → İnsan onayı → Yeni revision → Yeni hash/evidence baseline.

Eski revision silinmez. Test değişikliği ayrıca Test Change Request ister. HARD dependency bypass yalnız `DEPENDENCY_BYPASS_TEMPLATE` ile ve insan onayıyla mümkündür.
