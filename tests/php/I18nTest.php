<?php
use PHPUnit\Framework\TestCase;

class I18nTest extends TestCase
{
	public function test_datepicker_uses_wp_locale_months(): void
	{
		$oLocale = (object)array(
			'weekday'         => array('Söndag', 'Måndag', 'Tisdag', 'Onsdag', 'Torsdag', 'Fredag', 'Lördag'),
			'weekday_abbrev'  => array('Sön', 'Mån', 'Tis', 'Ons', 'Tor', 'Fre', 'Lör'),
			'weekday_initial' => array('Sö', 'Må', 'Ti', 'On', 'To', 'Fr', 'Lö'),
			'month'           => array('januari', 'februari', 'mars', 'april', 'maj', 'juni', 'juli', 'augusti', 'september', 'oktober', 'november', 'december'),
			'month_abbrev'    => array('jan', 'feb', 'mar', 'apr', 'maj', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'),
		);
		$a = TPFW_Datepicker_Locale::from_wp_locale($oLocale, 'Idag', 'Rensa');
		$this->assertSame('januari', $a['months'][0]);
		$this->assertSame('Söndag', $a['days'][0]);
		$this->assertSame('Idag', $a['today']);
	}

	public function test_unedited_email_follows_default_edited_stays(): void
	{
		$aDefaults = array(
			'resend_ticket_email' => array(
				'subject' => 'Your ticket',
				'message' => 'Hello',
			),
		);
		$this->assertSame('Your ticket', TPFW_Email_Settings::resolve(array(), 'resend_ticket_email', 'subject', $aDefaults));
		$this->assertSame('Sparat ämne', TPFW_Email_Settings::resolve(
			array('resend_ticket_email_subject' => 'Sparat ämne'),
			'resend_ticket_email',
			'subject',
			$aDefaults
		));
	}

	public function test_functions_datepicker_delegates_to_wp_locale(): void
	{
		$sSrc = file_get_contents(TPFW_PLUGIN_DIR.'inc/functions/class--functions.php');
		$this->assertNotFalse(strpos($sSrc, 'TPFW_Datepicker_Locale::from_wp_locale'));
		$this->assertFalse(strpos($sSrc, "__('Sunday', 'tickets-passes-for-woocommerce')"));
	}

	public function test_ticket_id_uses_sprintf(): void
	{
		$sSrc = file_get_contents(TPFW_PLUGIN_DIR.'inc/functions/class--functions.php');
		$this->assertNotFalse(strpos($sSrc, "__('Ticket ID: %s'"));
	}
}
