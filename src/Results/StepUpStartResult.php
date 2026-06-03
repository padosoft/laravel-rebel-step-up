<?php

declare(strict_types=1);

namespace Padosoft\Rebel\StepUp\Results;

/**
 * Esito dell'avvio di uno step-up: l'id della challenge step-up, il driver scelto,
 * e l'eventuale riferimento del driver (es. id challenge OTP) da mostrare al client.
 */
final readonly class StepUpStartResult
{
    public function __construct(
        public string $challengeId,
        public string $driver,
        public ?string $reference = null,
    ) {}
}
