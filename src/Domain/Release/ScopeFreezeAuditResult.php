<?php

declare(strict_types=1);

namespace Coleza\Domain\Release;

final readonly class ScopeFreezeAuditResult
{
    /**
     * @param list<string> $v1MustScopes
     * @param list<string> $v1FoundationScopes
     * @param list<string> $forbiddenScopesChecked
     * @param list<string> $violations
     */
    public function __construct(
        public bool $isPassed,
        public int $v1MustCount,
        public int $v1FoundationCount,
        public array $v1MustScopes = [],
        public array $v1FoundationScopes = [],
        public array $forbiddenScopesChecked = [],
        public array $violations = [],
    ) {}
}
