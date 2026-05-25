<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Entity;

use App\Constants\OrderLogConstants;
use App\Model\Entity\OrderLog;
use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;

/**
 * OrderLog entity test case.
 */
class OrderLogTest extends TestCase
{
    public function testIsOrphanWhenOrderIdNull(): void
    {
        $log = new OrderLog(['order_id' => null]);
        $this->assertTrue($log->isOrphan());
    }

    public function testIsNotOrphanWhenOrderIdSet(): void
    {
        $log = new OrderLog(['order_id' => 5]);
        $this->assertFalse($log->isOrphan());
    }

    public function testGetKindLabelMapsCorrectly(): void
    {
        $this->assertSame(
            'Creado',
            (new OrderLog(['kind' => OrderLogConstants::KIND_CREATED]))->getKindLabel(),
        );
        $this->assertSame(
            'Cancelado',
            (new OrderLog(['kind' => OrderLogConstants::KIND_CANCELLED]))->getKindLabel(),
        );
    }

    public function testGetIconMapsCorrectly(): void
    {
        $log = new OrderLog(['kind' => OrderLogConstants::KIND_CREATED]);
        $this->assertSame('bi-plus-circle', $log->getIcon());
    }

    public function testGetIconUnknownKindReturnsFallback(): void
    {
        $log = new OrderLog(['kind' => 'unknown']);
        $this->assertSame('bi-circle', $log->getIcon());
    }

    public function testGetFormattedDateFormats(): void
    {
        $log = new OrderLog(['created' => new DateTime('2026-05-23 14:32:00')]);
        $formatted = $log->getFormattedDate();
        $this->assertNotSame('', $formatted);
        // Either "23/05/2026 14:32" or "23/5/2026 14:32" depending on locale.
        $this->assertMatchesRegularExpression('#23/0?5/2026 14:32#', $formatted);
    }
}
