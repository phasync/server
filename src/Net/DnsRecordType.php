<?php

namespace phasync\Net;

/**
 * DNS record types for resolution.
 */
enum DnsRecordType: int
{
    case A = 1;      // IPv4 address
    case AAAA = 28;  // IPv6 address
    case ANY = 255;  // Any record type (queries A then AAAA)
}
