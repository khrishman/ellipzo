<?php

declare(strict_types=1);

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * AuditEvent::record() was called with both entityId and entityKey
 * non-null. At most one identifier may be supplied per audit event -
 * supplying neither remains allowed, for backward compatibility with
 * entity-less audit events.
 */
final class InvalidAuditEventIdentifierException extends InvalidArgumentException {}
