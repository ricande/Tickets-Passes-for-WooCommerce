<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * Shared shell for the six settings tabs (General, Ticket, Timeslot Ticket, Pass, Scanner / API,
 * Email).
 *
 * Every tab is the same shape: a submenu entry reached through the tab strip, one template,
 * one script with a save nonce, one stylesheet, and an AJAX save handler. The six classes
 * used to repeat that shell; now a subclass sets a handful of properties and implements the
 * save. TPFW_Type_Settings_Tab below covers the three product-type tabs entirely, since their
 * saves differ only in option keys and colour defaults.
 *
 * @package Tickets_Passes_For_WooCommerce
 */
abstract class TPFW_Settings_Tab
{
	/** @var string Option/handle prefix ('tpfw'). */
	protected $sPrefix;
	/** @var TPFW_Functions Shared helper instance. */
	protected $oFunctions;

	/** @var string Admin page slug, e.g. 'tpfw-pass-settings'. */
	protected $sSlug;
	/** @var string Key print_settings_navigation() marks active. */
	protected $sTabKey;
	/** @var string Translated menu label. */
	protected $sMenuLabel;
	/** @var string Parent menu slug: 'hidden-tpfw' keeps the tab out of the sidebar. */
	protected $sParent = 'hidden-tpfw';
	/** @var string Template path relative to the subclass directory. */
	protected $sTemplate;
	/** @var string Script path relative to the subclass directory; '' uses the shared settings-tab.js. */
	protected $sScriptFile = '';
	/** @var string Stylesheet path relative to the subclass directory; '' when the shared settings.css is enough. */
	protected $sStyleFile = '';
	/** @var array Script dependencies beyond jQuery. */
	protected $aScriptDeps = array();
	/** @var string Name of the localized parameter object the script reads. */
	protected $sParamsName = 'tpfwParamsSettingsTab';
	/** @var string AJAX save action without the tpfw_ prefix. */
	protected $sSaveAction;
	/** @var string Option the template reads into $settingsOptions. */
	protected $sOptionName = 'tpfw_general_settings_options';
	/** @var string TPFW_Functions sanitizer to register for the option, or '' when another tab registers it. */
	protected $sSanitizeMethod = '';

	/**
	 * @param string         $sPrefix    Option/handle prefix for the plugin ('tpfw').
	 * @param TPFW_Functions $oFunctions Shared helper instance.
	 */
	public function __construct($sPrefix, $oFunctions)
	{
		$this->sPrefix    = $sPrefix;
		$this->oFunctions = $oFunctions;
		$this->run();
	}

	/**
	 * Directory of the subclass file, for its template and assets.
	 *
	 * @return string
	 */
	abstract protected function get_dir();

	/**
	 * Saves the tab. Must verify the nonce and manage_woocommerce through guard_ajax().
	 *
	 * @return void
	 */
	abstract public function ajax_save_callback();

	/**
	 * Registers the page, its assets, the option and the save handler.
	 *
	 * @return void
	 */
	protected function run()
	{
		add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
		add_action('admin_menu',            array($this, 'add_settings_page'));
		add_action('wp_ajax_tpfw_' . $this->sSaveAction, array($this, 'ajax_save_callback'));
		if($this->sSanitizeMethod !== '')
		{
			add_action('admin_init', array($this, 'register_option'));
		}
		$this->register_extra();
	}

	/**
	 * Hook for a subclass to register further actions.
	 *
	 * @return void
	 */
	protected function register_extra() {}

	/**
	 * Registers the tab's option with its sanitizer, so a direct options.php write is sanitized too.
	 *
	 * @return void
	 */
	public function register_option()
	{
		register_setting($this->sOptionName, $this->sOptionName, array('type' => 'array', 'sanitize_callback' => array($this->oFunctions, $this->sSanitizeMethod), 'default' => array()));
	}

	/**
	 * Adds the tab to the admin menu.
	 *
	 * @return void
	 */
	public function add_settings_page()
	{
		add_submenu_page($this->sParent, $this->sMenuLabel, $this->sMenuLabel, 'manage_woocommerce', $this->sSlug, array($this, 'page_content'));
	}

	/**
	 * Prints the tab strip and includes the template with $settingsOptions loaded.
	 *
	 * @return void
	 */
	public function page_content()
	{
		$this->oFunctions->print_settings_navigation($this->sTabKey);
		$settingsOptions = $this->get_template_options();
		include($this->get_dir() . '/' . $this->sTemplate);
	}

	/**
	 * Options handed to the template.
	 *
	 * @return array
	 */
	protected function get_template_options()
	{
		return (array) get_option($this->sOptionName, array());
	}

	/**
	 * Whether the current admin request is this tab.
	 *
	 * @return bool
	 */
	protected function is_own_screen()
	{
		global $pagenow;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of the current admin screen to decide whether to enqueue assets; no state is changed.
		return isset($pagenow) && $pagenow == 'admin.php' && isset($_GET['page']) && sanitize_text_field(wp_unslash($_GET['page'] ?? '')) == $this->sSlug;
	}

	/**
	 * Values localized for the tab's script.
	 *
	 * @return array
	 */
	protected function get_script_params()
	{
		return array(
			'aNonces'      => $this->oFunctions->get_ajax_nonces(array($this->sSaveAction)),
			'sAction'      => $this->sSaveAction,
			'translations' => array(
				'sSaveFailed' => __('The settings could not be saved. Please try again.', 'tickets-passes-for-woocommerce'),
				'sSaved'      => __('Settings saved.', 'tickets-passes-for-woocommerce'),
			),
		);
	}

	/**
	 * Loads the shared stylesheet plus the tab's own script and stylesheet, and only on this tab.
	 *
	 * @return void
	 */
	public function enqueue_assets()
	{
		if(!$this->is_own_screen()) return;

		$sSharedDir = dirname(__FILE__);
		$sSharedURL = plugins_url('', __FILE__);
		wp_enqueue_style($this->sPrefix.'settings', $sSharedURL.'/css/settings.css', array(), filemtime($sSharedDir.'/css/settings.css'));

		$sHandle = $this->sPrefix . $this->sSlug;
		if($this->sScriptFile === '')
		{
			wp_register_script($sHandle, $sSharedURL.'/js/settings-tab.js', array_merge(array('jquery'), $this->aScriptDeps), filemtime($sSharedDir.'/js/settings-tab.js'), true);
		}
		else
		{
			wp_register_script($sHandle, plugins_url($this->sScriptFile, $this->get_dir() . '/x'), array_merge(array('jquery'), $this->aScriptDeps), filemtime($this->get_dir() . '/' . $this->sScriptFile), true);
		}
		wp_enqueue_script($sHandle);
		wp_localize_script($sHandle, $this->sParamsName, $this->get_script_params());

		if($this->sStyleFile !== '')
		{
			wp_enqueue_style($sHandle, plugins_url($this->sStyleFile, $this->get_dir() . '/x'), array($this->sPrefix.'settings'), filemtime($this->get_dir() . '/' . $this->sStyleFile));
		}

		$this->oFunctions->enqueue_help_tip_assets();
	}

	/**
	 * Nonce + capability gate for the save handler. Sends the JSON error itself.
	 *
	 * @return array Empty response array to fill.
	 */
	protected function guard_ajax()
	{
		check_ajax_referer('tpfw_' . $this->sSaveAction, 'security');
		if(!$this->oFunctions->user_can_manage())
		{
			wp_send_json_error(array('sMessage' => __('Current user does not have admin privileges', 'tickets-passes-for-woocommerce')));
		}
		return array();
	}

	/**
	 * Merges the given keys into the shared general option, leaving other tabs' keys untouched.
	 *
	 * @param array $aValues Keys to write.
	 * @return void
	 */
	protected function merge_general_options($aValues)
	{
		$aExisting = (array) get_option('tpfw_general_settings_options', array());
		update_option('tpfw_general_settings_options', array_merge($aExisting, $aValues));
	}
}


/**
 * The Ticket, Timeslot Ticket and Pass settings tabs: an enable toggle, a hint text and a set
 * of colour rows, all stored in tpfw_general_settings_options under type-prefixed keys.
 */
abstract class TPFW_Type_Settings_Tab extends TPFW_Settings_Tab
{
	/** @var string Class-name key the template uses: 'pass', 'ticket' or 'timeslot'. */
	protected $sTypeKey;
	/** @var string Option key of the enable toggle, e.g. 'bEnablePassProduct'. */
	protected $sEnableKey;
	/** @var string Option key of the hint text, e.g. 'sPassHintText'. */
	protected $sHintKey;
	/** @var string Translated default hint text. */
	protected $sHintDefault;
	/** @var array Colour option key => default hex. */
	protected $aColorDefaults = array();
	/** @var string Selector of the rows that only apply while the type is enabled. */
	protected $sExtraRowSelector;
	/** @var string Translated success message. */
	protected $sSavedMessage;
	/** @var string Translated message for an invalid colour. */
	protected $sColorError;

	/**
	 * The shared settings-tab.js is driven entirely by these: which selectors to read, which
	 * toggle hides which rows, and which colour pickers mirror which hex fields.
	 *
	 * @return array
	 */
	protected function get_script_params()
	{
		$aFields = array(
			array('sKey' => $this->sEnableKey, 'sSelector' => '.tpfw-' . $this->sTypeKey . '-enable', 'sType' => 'checkbox'),
			array('sKey' => $this->sHintKey,   'sSelector' => '.tpfw-' . $this->sTypeKey . '-hint-text', 'sType' => 'text'),
		);
		$aColorRows = array();
		foreach(array_keys($this->aColorDefaults) as $sColorKey)
		{
			// 'sPassColorDayName' -> 'dayname': the template names its rows after the suffix.
			$sRow      = strtolower(substr($sColorKey, strpos($sColorKey, 'Color') + 5));
			$aFields[] = array('sKey' => $sColorKey, 'sSelector' => '.tpfw-' . $this->sTypeKey . '-color-' . $sRow . '-hexcolor', 'sType' => 'text');
			$aColorRows[] = array('sPicker' => '.tpfw-' . $this->sTypeKey . '-color-' . $sRow . '-colorpicker', 'sHex' => '.tpfw-' . $this->sTypeKey . '-color-' . $sRow . '-hexcolor');
		}

		return array_merge(parent::get_script_params(), array(
			'sSaveButton'       => '.tpfw-admin-' . str_replace('tpfw-', '', str_replace('-settings', '', $this->sSlug)) . '-settings-save',
			'aFields'           => $aFields,
			'sEnableSelector'   => '.tpfw-' . $this->sTypeKey . '-enable',
			'sExtraRowSelector' => $this->sExtraRowSelector,
			'aColorRows'        => $aColorRows,
		));
	}

	/**
	 * Row key => the front-end custom property that row feeds.
	 *
	 * The counterpart of TPFW_Functions::get_front_color_style(), which writes these same
	 * properties onto the product page. The preview reads them off the row's data attribute, so
	 * it paints whatever the front end paints; tests/test-color-preview-vars.php fails if the
	 * two ever disagree, because a preview showing the wrong colour is worse than no preview.
	 *
	 * @var array
	 */
	protected static $aColorVars = array(
		'accent'     => '--tpfw-accent',
		'text'       => '--tpfw-ink',
		'border'     => '--tpfw-border',
		'background' => '--tpfw-bg',
		'hint'       => '--tpfw-hint',
		'dayname'    => '--tpfw-dayname',
		'navtitle'   => '--tpfw-navtitle',
	);

	/**
	 * Prints one form-table row per colour setting.
	 *
	 * The option keys, their shipped defaults and the row class names all come from
	 * $aColorDefaults, which is the same array the save handler validates against - so a
	 * template only says what each row is called and what it changes, and a default can no
	 * longer drift between the field, its reset button and the save.
	 *
	 * @param array $aRows     Row key ('accent', 'dayname', ...) => array(label, help tip).
	 * @param array $aOptions  The stored options, as handed to the template.
	 * @param bool  $bEnabled  Whether the product type is switched on.
	 * @return void
	 */
	protected function print_color_rows($aRows, $aOptions, $bEnabled)
	{
		$aSwatches   = $this->get_palette_swatches();
		$sRowClasses = ltrim($this->sExtraRowSelector, '.') . ($bEnabled ? '' : ' hide');

		foreach($this->aColorDefaults as $sOptionKey => $sDefault)
		{
			// 'sPassColorDayName' -> 'dayname', the same derivation get_script_params() uses.
			$sRow = strtolower(substr($sOptionKey, strpos($sOptionKey, 'Color') + 5));
			if(!isset($aRows[$sRow])) continue;

			$sValue = (isset($aOptions[$sOptionKey]) && $aOptions[$sOptionKey] !== '') ? $aOptions[$sOptionKey] : $sDefault;
			$sBase  = 'tpfw-' . $this->sTypeKey . '-color-' . $sRow;
			?>
			<tr class="<?php echo esc_attr($sRowClasses); ?>">
				<th scope="row"><?php echo esc_html($aRows[$sRow][0]); ?> <?php echo wp_kses_post(wc_help_tip($aRows[$sRow][1])); ?></th>
				<td>
					<div class="tpfw-colorpicker" data-tpfw-var="<?php echo esc_attr(isset(self::$aColorVars[$sRow]) ? self::$aColorVars[$sRow] : ''); ?>">
						<input type="color" class="<?php echo esc_attr($sBase); ?>-colorpicker colorpick-eyedropper-input-trigger tpfw-colorpicker-swatch" value="<?php echo esc_attr($sValue); ?>" aria-label="<?php /* translators: %s: name of the colour setting, e.g. "Accent Color". */ echo esc_attr(sprintf(__('Pick %s', 'tickets-passes-for-woocommerce'), $aRows[$sRow][0])); ?>">
						<input type="text" class="<?php echo esc_attr($sBase); ?>-hexcolor tpfw-colorpicker-hex" value="<?php echo esc_attr($sValue); ?>" maxlength="7" placeholder="<?php echo esc_attr($sDefault); ?>" aria-label="<?php /* translators: %s: name of the colour setting, e.g. "Accent Color". */ echo esc_attr(sprintf(__('%s as a hex value', 'tickets-passes-for-woocommerce'), $aRows[$sRow][0])); ?>">
						<button type="button" class="tpfw-colorpicker-reset" data-tpfw-default="<?php echo esc_attr($sDefault); ?>"><?php echo esc_html__('Use default color', 'tickets-passes-for-woocommerce'); ?></button>
					</div>
					<?php if(!empty($aSwatches)) : ?>
					<div class="tpfw-colorpicker-palette">
						<?php foreach($aSwatches as $aSwatch) : ?>
						<button type="button" data-tpfw-color="<?php echo esc_attr($aSwatch['color']); ?>" style="background:<?php echo esc_attr($aSwatch['color']); ?>" title="<?php echo esc_attr($aSwatch['name'] . ' ' . $aSwatch['color']); ?>"><span class="screen-reader-text"><?php echo esc_html($aSwatch['name']); ?></span></button>
						<?php endforeach; ?>
					</div>
					<?php endif; ?>
				</td>
			</tr>
			<?php
		}
	}

	/**
	 * The preset swatches offered under each colour row.
	 *
	 * Taken from the editor palette rather than a list of our own: on a block theme these really
	 * are the shop's colours, and on a classic theme they are core's stock set, which is still
	 * what the shop sees everywhere else in wp-admin.
	 *
	 * @return array List of array('color' => '#rrggbb', 'name' => string).
	 */
	protected function get_palette_swatches()
	{
		$aPalette = function_exists('wp_get_global_settings') ? wp_get_global_settings(array('color', 'palette')) : array();
		$aSource  = !empty($aPalette['theme']) ? $aPalette['theme'] : (!empty($aPalette['default']) ? $aPalette['default'] : array());

		$aSwatches = array();
		foreach((array) $aSource as $aEntry)
		{
			// Both controls in the row only speak six-digit hex, so a palette entry that is a
			// gradient, an rgba() or a var() is dropped rather than offered and then rejected.
			$sColor = isset($aEntry['color']) ? trim($aEntry['color']) : '';
			if(!preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $sColor)) continue;
			if(strlen($sColor) === 4) $sColor = '#' . $sColor[1] . $sColor[1] . $sColor[2] . $sColor[2] . $sColor[3] . $sColor[3];
			$aSwatches[] = array('color' => strtolower($sColor), 'name' => isset($aEntry['name']) ? $aEntry['name'] : $sColor);
		}
		return $aSwatches;
	}

	/**
	 * Prints the live preview beside the colour rows.
	 *
	 * A stand-in for the product page rather than the page itself: the same custom properties,
	 * the same parts in the same order, at a size that fits a settings sidebar. It is deliberately
	 * not the real component - pulling the front-end stylesheets into wp-admin would leave the
	 * preview at the mercy of whatever the admin sheet does to a div, which is a worse lie than a
	 * simplified drawing.
	 *
	 * The light/dark switch changes only what the card is sitting on, since that is the one thing
	 * about the customer's view these settings cannot control.
	 *
	 * Carries the same extra-row class as the colour rows, so it goes with them when the product
	 * type is switched off - a preview of a card no customer can reach is just clutter.
	 *
	 * @param bool $bEnabled Whether the product type is switched on.
	 * @return void
	 */
	protected function print_color_preview($bEnabled)
	{
		// 'sTicketColorAccent' -> 'sTicketColor', the prefix the front end themes itself from, so
		// the preview starts on the stored colours without waiting for the script.
		$aKeys   = array_keys($this->aColorDefaults);
		$sPrefix = substr($aKeys[0], 0, strpos($aKeys[0], 'Color') + 5);
		?>
		<aside class="tpfw-preview <?php echo esc_attr(ltrim($this->sExtraRowSelector, '.') . ($bEnabled ? '' : ' hide')); ?>">
			<div class="tpfw-preview-head">
				<h3><?php echo esc_html__('Preview', 'tickets-passes-for-woocommerce'); ?></h3>
				<div class="tpfw-preview-theme">
					<button type="button" data-tpfw-theme="light" class="is-on"><?php echo esc_html__('Light theme', 'tickets-passes-for-woocommerce'); ?></button>
					<button type="button" data-tpfw-theme="dark"><?php echo esc_html__('Dark theme', 'tickets-passes-for-woocommerce'); ?></button>
				</div>
			</div>

			<div class="tpfw-preview-stage" data-theme="light" style="<?php echo esc_attr($this->oFunctions->get_front_color_style($sPrefix)); ?>">
				<?php $this->print_preview_card(); ?>
			</div>

			<p class="tpfw-preview-note"><?php echo esc_html__('Everything around the card - the page background, the price and the Add to cart button - belongs to your theme, not to these settings.', 'tickets-passes-for-woocommerce'); ?></p>
		</aside>
		<?php
	}

	/**
	 * The product-type-specific part of the preview.
	 *
	 * @return void
	 */
	protected function print_preview_card()
	{
		$aDayNames = array(
			__('MO', 'tickets-passes-for-woocommerce'), __('TU', 'tickets-passes-for-woocommerce'),
			__('WE', 'tickets-passes-for-woocommerce'), __('TH', 'tickets-passes-for-woocommerce'),
			__('FR', 'tickets-passes-for-woocommerce'), __('SA', 'tickets-passes-for-woocommerce'),
			__('SU', 'tickets-passes-for-woocommerce'),
		);
		?>
		<?php if($this->sTypeKey === 'pass') : ?>
			<div class="tpfw-pv-card">
				<div class="tpfw-pv-head">
					<div>
						<span class="tpfw-pv-title"><?php echo esc_html__('Passes', 'tickets-passes-for-woocommerce'); ?></span>
						<p class="tpfw-pv-hint"><?php echo esc_html__('Add a name for each person this pass covers.', 'tickets-passes-for-woocommerce'); ?></p>
					</div>
					<span class="tpfw-pv-pill">&minus; <?php echo esc_html__('2 people', 'tickets-passes-for-woocommerce'); ?> +</span>
				</div>
				<div class="tpfw-pv-person">
					<span class="tpfw-pv-avatar is-named">1</span>
					<span class="tpfw-pv-field is-filled"><?php echo esc_html__('Alex Rivera', 'tickets-passes-for-woocommerce'); ?></span>
				</div>
				<div class="tpfw-pv-person">
					<span class="tpfw-pv-avatar">2</span>
					<span class="tpfw-pv-field"><?php echo esc_html__('Firstname', 'tickets-passes-for-woocommerce'); ?></span>
				</div>
				<div class="tpfw-pv-foot"><?php echo esc_html__('1 of 2 named', 'tickets-passes-for-woocommerce'); ?></div>
			</div>
		<?php else : ?>
			<?php if($this->sTypeKey === 'ticket') : ?>
			<div class="tpfw-pv-card tpfw-pv-info">
				<div class="tpfw-pv-inforow"><span><?php echo esc_html__('Valid from', 'tickets-passes-for-woocommerce'); ?></span><b><?php echo esc_html__('12-09-2026', 'tickets-passes-for-woocommerce'); ?></b></div>
				<div class="tpfw-pv-inforow"><span><?php echo esc_html__('Maximum uses', 'tickets-passes-for-woocommerce'); ?></span><b>3</b></div>
			</div>
			<?php endif; ?>
			<div class="tpfw-pv-card">
				<div class="tpfw-pv-head">
					<div>
						<span class="tpfw-pv-title"><?php echo esc_html__('Select a Date', 'tickets-passes-for-woocommerce'); ?></span>
						<p class="tpfw-pv-hint"><?php echo esc_html($this->sHintDefault); ?></p>
					</div>
					<span class="tpfw-pv-pill"><?php echo esc_html($this->sTypeKey === 'ticket' ? __('12 Sep', 'tickets-passes-for-woocommerce') : __('2 tickets', 'tickets-passes-for-woocommerce')); ?></span>
				</div>
				<div class="tpfw-pv-navtitle"><?php echo esc_html__('September, 2026', 'tickets-passes-for-woocommerce'); ?></div>
				<div class="tpfw-pv-week">
					<?php foreach($aDayNames as $sDayName) : ?>
					<span class="tpfw-pv-dayname"><?php echo esc_html($sDayName); ?></span>
					<?php endforeach; ?>
					<?php foreach(array(7, 8, 9, 10, 11, 12, 13) as $iDay) : ?>
					<span class="tpfw-pv-day<?php echo esc_attr($iDay === 12 ? ' is-chosen' : ($iDay > 12 ? ' is-gone' : '')); ?>"><?php echo esc_html($iDay); ?></span>
					<?php endforeach; ?>
				</div>
				<?php if($this->sTypeKey === 'timeslot') : ?>
				<div class="tpfw-pv-slot is-chosen"><span>10:00 &ndash; 11:00</span><b><?php echo esc_html__('8 left', 'tickets-passes-for-woocommerce'); ?></b></div>
				<div class="tpfw-pv-slot"><span>11:00 &ndash; 12:00</span><b><?php echo esc_html__('2 left', 'tickets-passes-for-woocommerce'); ?></b></div>
				<?php else : ?>
				<div class="tpfw-pv-foot"><?php echo esc_html__('Valid from 12 September 2026', 'tickets-passes-for-woocommerce'); ?></div>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Saves the toggle, the hint text and the colours, each validated by shape.
	 *
	 * @return void
	 */
	public function ajax_save_callback()
	{
		$response = $this->guard_ajax();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard_ajax() verified the nonce.
		$bEnabled = !empty($_POST[$this->sEnableKey]) ? 1 : 0;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
		$sHint = sanitize_textarea_field(wp_unslash($_POST[$this->sHintKey] ?? ''));
		if(trim($sHint) === '') $sHint = $this->sHintDefault;

		$aColors = array();
		foreach($this->aColorDefaults as $sColorKey => $sDefaultHex)
		{
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
			$sRaw = sanitize_text_field(wp_unslash($_POST[$sColorKey] ?? ''));
			if($sRaw === '')
			{
				$aColors[$sColorKey] = $sDefaultHex;
				continue;
			}
			$sHex = sanitize_hex_color(strpos($sRaw, '#') === 0 ? $sRaw : '#' . $sRaw);
			if(!$sHex)
			{
				wp_send_json_error(array('sMessage' => $this->sColorError));
			}
			$aColors[$sColorKey] = $sHex;
		}

		$this->merge_general_options(array_merge(array($this->sEnableKey => $bEnabled, $this->sHintKey => $sHint), $aColors));

		$response['sMessage'] = $this->sSavedMessage;
		wp_send_json_success($response);
	}
}
