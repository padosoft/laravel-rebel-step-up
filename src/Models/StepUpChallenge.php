<?php

declare(strict_types=1);

namespace Padosoft\Rebel\StepUp\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Padosoft\Rebel\Core\Concerns\BelongsToTenant;
use Padosoft\Rebel\StepUp\Enums\StepUpStatus;

/**
 * Una sfida di step-up. Quando `status = verified` e non scaduta la "finestra di
 * conferma" (verified_at + ttl), e il binding_hash combacia (per i purpose SCA),
 * vale come conferma valida per quel purpose.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $subject_type
 * @property string $subject_id
 * @property string|null $guard
 * @property string|null $device_id
 * @property string $purpose
 * @property string $required_assurance
 * @property bool $require_phishing_resistant
 * @property string|null $achieved_assurance
 * @property bool|null $achieved_phishing_resistant
 * @property bool|null $achieved_restricted
 * @property string $selected_driver
 * @property string|null $driver_ref
 * @property string|null $binding_hash
 * @property int|null $key_version
 * @property StepUpStatus $status
 * @property int $attempts
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $verified_at
 */
final class StepUpChallenge extends Model
{
    use BelongsToTenant;

    protected $table = 'rebel_step_up_challenges';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'subject_type', 'subject_id', 'guard', 'device_id',
        'purpose', 'required_assurance', 'require_phishing_resistant', 'achieved_assurance',
        'achieved_phishing_resistant', 'achieved_restricted',
        'selected_driver', 'driver_ref', 'binding_hash', 'bound_amount', 'bound_currency',
        'bound_payee', 'bound_order_ref', 'key_version', 'status', 'attempts',
        'risk_score', 'risk_reasons', 'expires_at', 'verified_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StepUpStatus::class,
            'require_phishing_resistant' => 'boolean',
            'achieved_phishing_resistant' => 'boolean',
            'achieved_restricted' => 'boolean',
            'attempts' => 'integer',
            'key_version' => 'integer',
            'risk_reasons' => 'array',
            'expires_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
        ];
    }

    public function isExpiredAt(DateTimeInterface $now): bool
    {
        return $now > $this->expires_at;
    }
}
