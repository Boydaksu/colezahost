<?php

declare(strict_types=1);

namespace Coleza\Domain\Domains\Registrar;

final class RegistrarCapability
{
    public const AVAILABILITY_CHECK = 'availability_check';
    public const REGISTER = 'register';
    public const RENEW = 'renew';
    public const TRANSFER = 'transfer';
    public const GET_NAMESERVERS = 'get_nameservers';
    public const UPDATE_NAMESERVERS = 'update_nameservers';
    public const GET_LOCK = 'get_lock';
    public const SET_LOCK = 'set_lock';
    public const GET_EPP_CODE = 'get_epp_code';
    public const GET_CONTACTS = 'get_contacts';
    public const UPDATE_CONTACTS = 'update_contacts';
    public const DNS_MANAGEMENT = 'dns_management';
    public const EMAIL_FORWARDING = 'email_forwarding';
    public const ID_PROTECTION = 'id_protection';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::AVAILABILITY_CHECK,
            self::REGISTER,
            self::RENEW,
            self::TRANSFER,
            self::GET_NAMESERVERS,
            self::UPDATE_NAMESERVERS,
            self::GET_LOCK,
            self::SET_LOCK,
            self::GET_EPP_CODE,
            self::GET_CONTACTS,
            self::UPDATE_CONTACTS,
            self::DNS_MANAGEMENT,
            self::EMAIL_FORWARDING,
            self::ID_PROTECTION,
        ];
    }
}
