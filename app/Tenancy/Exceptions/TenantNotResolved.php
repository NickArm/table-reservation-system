<?php

declare(strict_types=1);

namespace App\Tenancy\Exceptions;

use RuntimeException;

/**
 * Thrown when a tenant-scoped model is queried with no tenant in context.
 *
 * This is deliberately loud. A silent unscoped query on a multi-tenant table
 * is a data leak, so the application refuses instead of guessing.
 */
final class TenantNotResolved extends RuntimeException {}
