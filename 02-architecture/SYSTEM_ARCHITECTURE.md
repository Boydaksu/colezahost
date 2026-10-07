# System Architecture

## Layers
1. Framework/Foundation
2. Platform Core
3. Business Domains
4. Modules/Providers/Integrations
5. UI/Themes

## Deployment philosophy
Baseline: PHP 8.4+, MariaDB/MySQL supported matrix, filesystem, cron <=15min, HTTP, preferably OPcache. Enhanced profile may add Redis, persistent workers, SSE/WebSocket, external telemetry/search without changing business code.

## Core principles
- Modular monolith, explicit bounded contexts.
- Application Layer is the only business use-case entry point.
- Controllers/UI/API/CLI/automation/extensions never implement business rules.
- Domain→Infrastructure dependency forbidden; infrastructure implements contracts.
- Cross-domain direct repository access forbidden; Commands/Queries/Events/Contracts only.
- Critical final records immutable or corrected via adjustment/reversal/amendment.
- Business state source-of-truth lives in domain persistence; cache/analytics are derived.
- Async side effects use queue; long work never relies on a single HTTP request.

## Shared-host baseline adapters
DatabaseQueue, DatabaseLock, DB/File cache, scheduler invoked by cron, private filesystem storage. Enhanced adapters are optional.
