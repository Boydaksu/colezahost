<?php

declare(strict_types=1);

namespace Coleza\Domain\Abuse;

enum AbuseCategory: string
{
    case PHISHING = 'phishing';
    case MALWARE = 'malware';
    case SPAM = 'spam';
    case DMCA_COPYRIGHT = 'dmca_copyright';
    case RESOURCE_ABUSE_DDOS = 'resource_abuse_ddos';
    case BOTNET_C2 = 'botnet_c2';
    case ILLEGAL_CONTENT = 'illegal_content';
    case OTHER = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::PHISHING => 'Phishing / Credential Harvesting',
            self::MALWARE => 'Malware / Exploit Hosting',
            self::SPAM => 'Unsolicited Commercial Email (SPAM)',
            self::DMCA_COPYRIGHT => 'DMCA / Intellectual Property Infringement',
            self::RESOURCE_ABUSE_DDOS => 'DDoS / Network Flooding / Resource Abuse',
            self::BOTNET_C2 => 'Botnet Command & Control (C2)',
            self::ILLEGAL_CONTENT => 'Prohibited / Illegal Content',
            self::OTHER => 'General Terms of Service Abuse',
        };
    }
}
