# Coleza Host — Hosting Commerce & Operations Platform

Bu paket, **Coleza Host** (sıfırdan geliştirilecek WiseCP/WHMCS alternatifi platform) için kilitlenmiş ürün kapsamını, mimari sınırları, V1/V1.1/V2+ fazlarını, test/release kapılarını ve AI geliştirme yönetişimini içerir.

## Temel hedef
V1; shared hosting/cPanel/Plesk ortamlarında Composer/Node/Redis/daemon zorunluluğu olmadan çalışabilen, müşteri → ürün → sipariş → fatura → ödeme → provisioning → hizmet → renewal → support akışını ve domain satış/yenileme akışını üretim kalitesinde yürüten ilk sürümdür.

## Okuma sırası
1. `00-overview/MASTER_ROADMAP.md`
2. `01-scope/V1_SCOPE_CONTRACT.md`
3. `02-architecture/SYSTEM_ARCHITECTURE.md`
4. `02-architecture/DEPENDENCY_MAP.md`
5. `03-constitution/*`
6. `04-ai-governance/AI_CONSTITUTION.md`
7. `05-testing/TEST_STRATEGY.md`
8. `06-phases/v1/*` → `v1.1/*` → `v2+/*`
9. `07-gates/*`
10. `10-release/*`

## Makine-okunabilir kaynaklar
`09-machine-readable/` altındaki JSON dosyaları scope, domain dependency ve faz ilişkilerinin canonical makine-okunabilir sürümüdür. Markdown açıklayıcıdır; çelişki halinde kilitli spec + machine-readable kayıt birlikte incelenir ve Change Request olmadan değiştirilemez.

## Kilitli ilkeler
- Modular monolith; microservice zorunluluğu yok.
- Native PHP 8.4+ hedefi; MariaDB 10.11+ ana hedef.
- Shared hosting baseline; Redis/worker/search engine yalnız opsiyonel hızlandırıcı.
- UI, API, CLI, automation ve modüller aynı Application Command/use-case katmanını kullanır.
- Finansal ve finalized kayıtlar destructive edit yerine reversal/adjustment/snapshot yaklaşımı kullanır.
- Cache hiçbir zaman source-of-truth değildir.
- Kritik yan etkiler idempotency + lock + DB constraint ile korunur.
- TR birincil, EN ikinci dildir; iki dilde eksik key release blocker'dır.
- AI kendi işini tek başına onaylayamaz; kanıt ve gate zorunludur.
