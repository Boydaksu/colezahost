# Glossary

- **Core/Foundation:** Framework-level teknik altyapı; business domain değildir.
- **Application Command:** UI/API/CLI/automation/modülün business use-case çağırdığı tek giriş noktası.
- **Bounded Context:** Aynı modular monolith içindeki bağımsız domain sınırı.
- **HARD Dependency:** Gate geçmeden downstream implementation başlayamaz.
- **SOFT Dependency:** Paralel olabilir; integration gate için gerekir.
- **V1-FOUNDATION:** Data model/contract/extension point var, tam feature yok.
- **Snapshot:** Finalization anındaki değişmez business veri kopyası.
- **Idempotency:** Aynı request/job tekrarlandığında ikinci yan etki üretmemesi.
- **DLQ:** Tekrarlı başarısız job'ların Dead Letter Queue alanı.
- **Golden Scenario/Dataset:** Beklenen çıktıları insan tarafından doğrulanmış sabit regresyon fixture'ı.
- **Phase Gate:** Bir sonraki faza geçiş için zorunlu makine + review kontrolü.
- **Domain Gate:** Bir bounded context'in production-ready kabul edilme kapısı.
- **Release Gate:** Stable package üretiminden önce tam sistem kontrolü.
