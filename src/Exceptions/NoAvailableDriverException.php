<?php

declare(strict_types=1);

namespace Padosoft\Rebel\StepUp\Exceptions;

use RuntimeException;

/**
 * No step-up driver available/eligible for the requested purpose.
 * Often means a misconfiguration (see rebel:validate-config) or a user without
 * suitable factors.
 */
final class NoAvailableDriverException extends RuntimeException {}
