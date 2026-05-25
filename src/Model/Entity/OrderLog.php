<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Constants\OrderLogConstants;
use Cake\ORM\Entity;

/**
 * @property int $id
 * @property int|null $order_id
 * @property int $order_id_snapshot
 * @property int|null $user_id
 * @property string $user_name_snapshot
 * @property string $kind
 * @property string $description
 * @property \Cake\I18n\DateTime|null $created
 * @property \App\Model\Entity\Order|null $order
 * @property \App\Model\Entity\User|null $user
 */
class OrderLog extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'order_id' => true,
        'order_id_snapshot' => true,
        'user_id' => true,
        'user_name_snapshot' => true,
        'kind' => true,
        'description' => true,
    ];

    /**
     * Returns the localised date string for UI display.
     */
    public function getFormattedDate(): string
    {
        return $this->created?->i18nFormat('dd/MM/yyyy HH:mm') ?? '';
    }

    /**
     * Returns the human-readable label for the log kind.
     */
    public function getKindLabel(): string
    {
        return OrderLogConstants::KIND_LABELS[$this->kind] ?? (string)$this->kind;
    }

    /**
     * Returns the Bootstrap Icons class for the log kind.
     */
    public function getIcon(): string
    {
        return OrderLogConstants::KIND_ICONS[$this->kind] ?? 'bi-circle';
    }

    /**
     * Whether the parent order has been deleted (FK SET NULL).
     */
    public function isOrphan(): bool
    {
        return $this->order_id === null;
    }
}
