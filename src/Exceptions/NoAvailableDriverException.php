<?php

declare(strict_types=1);

namespace Padosoft\Rebel\StepUp\Exceptions;

use RuntimeException;

/**
 * Nessun driver di step-up disponibile/idoneo per il purpose richiesto.
 * Spesso significa config errata (vedi rebel:validate-config) o utente senza
 * fattori adatti.
 */
final class NoAvailableDriverException extends RuntimeException {}
