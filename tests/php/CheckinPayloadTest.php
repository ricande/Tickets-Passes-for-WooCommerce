<?php
use PHPUnit\Framework\TestCase;

class CheckinPayloadTest extends TestCase
{
	public function test_success_payload_has_no_row_fields(): void
	{
		$oRow = (object)array(
			'user_id'        => 9,
			'order_id'       => 44,
			'user_payer_id'  => 9,
			'product_id'     => 19,
			'order_line_id'  => 3,
			'firstname'      => 'Ada',
			'lastname'       => 'Lovelace',
			'max_uses'       => 3,
			'valid_from'     => '2026-01-01 00:00:00',
			'valid_to'       => '2026-12-31 00:00:00',
		);

		$aBody = TPFW_Checkin_Payload::allowlist(array(
			'sMessage'        => 'Pass is valid and checked in',
			'sHexColor'       => '2e7d32',
			'sType'           => 'pass',
			'sHolderName'     => TPFW_Checkin_Payload::holder_name($oRow),
			'sPhotoURL'       => 'https://example.test/photo',
			'max_uses'        => $oRow->max_uses,
			'iUsesRemaining'  => 2,
			'valid_from'      => $oRow->valid_from,
			'valid_to'        => $oRow->valid_to,
			'ticket'          => $oRow,
			'user_id'         => $oRow->user_id,
			'order_id'        => $oRow->order_id,
			'user_payer_id'   => $oRow->user_payer_id,
		));

		$this->assertFalse(TPFW_Checkin_Payload::leaks_row_fields($aBody));
		$this->assertArrayNotHasKey('ticket', $aBody);
		$this->assertArrayNotHasKey('user_id', $aBody);
		$this->assertArrayNotHasKey('order_id', $aBody);
		$this->assertSame('Ada Lovelace', $aBody['sHolderName']);
		$this->assertSame('https://example.test/photo', $aBody['sPhotoURL']);
		$this->assertSame('Pass is valid and checked in', $aBody['sMessage']);
	}
}
