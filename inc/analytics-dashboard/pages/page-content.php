<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- this template is include()d inside a class method, so every variable in it is method-scope, not global.
/**
 * Markup for the Analytics dashboard page.
 *
 * Included from TPFW_Analytics_Dashboard, so $this is that class. Only the product types that are
 * switched on in the general settings get a filter checkbox here - the charts themselves are
 * empty on load and filled in by analytics-single.js over AJAX.
 *
 * The screen is a filter rail plus two stacked panels: the year of check-ins, and the hours of
 * whichever day of it is selected. Every string the script needs to swap in at runtime is printed
 * here rather than localised into JavaScript, so translators only ever see it in one place.
 */
$aProductTypes   = [];
$settingsOptions = get_option('tpfw_general_settings_options');
if(isset($settingsOptions['bEnableTicketProduct']) && $settingsOptions['bEnableTicketProduct'] != "") { $aProductTypes[] = 'tpfw-ticket'; }
if(isset($settingsOptions['bEnableTimeslotProduct']) && $settingsOptions['bEnableTimeslotProduct'] != "") { $aProductTypes[] = 'tpfw-timeslot-ticket'; }
if(isset($settingsOptions['bEnablePassProduct']) && $settingsOptions['bEnablePassProduct'] != "") { $aProductTypes[] = 'tpfw-pass'; }
if(empty($aProductTypes)) return;

$aProductTypeLabels = array(
    'tpfw-ticket'          => __("Tickets", 'tickets-passes-for-woocommerce'),
    'tpfw-timeslot-ticket' => __("Timeslot Tickets", 'tickets-passes-for-woocommerce'),
    'tpfw-pass'            => __("Passes", 'tickets-passes-for-woocommerce'),
);

$iCurrentYear = (int)gmdate('Y', current_time('timestamp'));
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only input used to decide what to display; no state is changed.
$sSelectedYear    = isset($_GET['year']) ? sanitize_text_field(wp_unslash($_GET['year'])) : (string)$iCurrentYear;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only input used to decide what to display; no state is changed.
$sSelectedProduct = isset($_GET['productid']) ? sanitize_text_field(wp_unslash($_GET['productid'])) : '';
?>
<div class="wrap tpfw-analytics">
    <header class="tpfw-analytics__masthead">
        <h1><?php esc_html_e('Analytics', 'tickets-passes-for-woocommerce'); ?></h1>
        <p class="tpfw-analytics__lede"><?php esc_html_e('Check-ins recorded at the door, day by day and hour by hour.', 'tickets-passes-for-woocommerce'); ?></p>
    </header>

    <div class="tpfw-notice notice notice-error" role="alert" hidden>
        <p class="tpfw-notice__text"></p>
    </div>

    <div class="tpfw-analytics__layout">
        <form class="tpfw-filters" novalidate>
            <div class="tpfw-filters__field">
                <label class="tpfw-filters__label" for="analytics-year"><?php esc_html_e('Year', 'tickets-passes-for-woocommerce'); ?></label>
                <select id="analytics-year" class="tpfw-select">
                    <?php
                        for($x = 0; $x <= ($iCurrentYear - 2025); $x++)
                        {
                            $iYear = $iCurrentYear - $x;
                            ?>
                                <option <?php selected($sSelectedYear, (string)$iYear); ?> value="<?php echo esc_attr($iYear); ?>"><?php echo esc_html($iYear); ?></option>
                            <?php
                        }
                    ?>
                </select>
            </div>

            <fieldset class="tpfw-filters__field tpfw-products">
                <legend class="tpfw-filters__label"><?php esc_html_e('Products', 'tickets-passes-for-woocommerce'); ?></legend>

                <div class="tpfw-products__bar">
                    <button type="button" class="tpfw-textbutton" data-tpfw-select="all"><?php esc_html_e('All', 'tickets-passes-for-woocommerce'); ?></button>
                    <button type="button" class="tpfw-textbutton" data-tpfw-select="none"><?php esc_html_e('None', 'tickets-passes-for-woocommerce'); ?></button>
                    <p class="tpfw-products__count"
                       aria-live="polite"
                       <?php /* translators: 1: number of products currently selected, 2: total number of products available. */ ?>
                       data-count-template="<?php echo esc_attr__('%1$s of %2$s selected', 'tickets-passes-for-woocommerce'); ?>"></p>
                </div>

                <div id="analytics-product" class="tpfw-products__list">
                    <?php
                        foreach($aProductTypes as $iProductTypeKey => $sProductType)
                        {
                            // limit -1: the default is posts_per_page (10), which silently hid
                            // every product past the tenth from the filter.
                            $aProducts = wc_get_products(
                                array(
                                    'type'  => strtolower($sProductType),
                                    'limit' => -1,
                                )
                            );
                            if(empty($aProducts)) continue;

                            ?>
                            <div class="tpfw-products__group">
                                <span class="tpfw-products__grouplabel"><?php echo esc_html($aProductTypeLabels[$sProductType]); ?></span>
                            <?php
                                foreach($aProducts as $iProductKey => $oProduct)
                                {
                                    $bChecked = ($sSelectedProduct === '' || $sSelectedProduct == $oProduct->get_id());
                                    ?>
                                        <label class="tpfw-products__row">
                                            <input type="checkbox" class="tpfw-product-checkbox" <?php checked($bChecked); ?> value="<?php echo esc_attr($oProduct->get_id()); ?>">
                                            <span class="tpfw-products__name"><?php echo esc_html($oProduct->get_name()); ?></span>
                                            <span class="tpfw-products__id">#<?php echo esc_html($oProduct->get_id()); ?></span>
                                        </label>
                                    <?php
                                }
                            ?>
                            </div>
                            <?php
                        }
                    ?>
                </div>
            </fieldset>

            <button type="submit" class="button button-primary analytics-search"><?php esc_html_e('Show check-ins', 'tickets-passes-for-woocommerce'); ?></button>
        </form>

        <div class="tpfw-analytics__main">
            <section class="tpfw-panel tpfw-panel--year" aria-busy="false">
                <div class="tpfw-panel__head">
                    <div class="tpfw-panel__heading">
                        <h2 class="tpfw-panel__title"><?php esc_html_e('Check-ins by day', 'tickets-passes-for-woocommerce'); ?></h2>
                        <p class="tpfw-panel__hint"><?php esc_html_e('Select a day to break it down by hour.', 'tickets-passes-for-woocommerce'); ?></p>
                    </div>
                    <button type="button" class="button tpfw-export" data-tpfw-export="year"><?php esc_html_e('Export year', 'tickets-passes-for-woocommerce'); ?></button>
                </div>

                <dl class="tpfw-readout" aria-live="polite">
                    <div class="tpfw-readout__item">
                        <dt><?php esc_html_e('Check-ins', 'tickets-passes-for-woocommerce'); ?></dt>
                        <dd data-readout="total">&mdash;</dd>
                    </div>
                    <div class="tpfw-readout__item">
                        <dt><?php esc_html_e('Busiest day', 'tickets-passes-for-woocommerce'); ?></dt>
                        <dd data-readout="peak">&mdash;</dd>
                    </div>
                    <div class="tpfw-readout__item">
                        <dt><?php esc_html_e('Days with check-ins', 'tickets-passes-for-woocommerce'); ?></dt>
                        <dd data-readout="active">&mdash;</dd>
                    </div>
                </dl>

                <div class="tpfw-panel__body">
                    <div class="tpfw-skeleton tpfw-skeleton--year" hidden></div>
                    <div id="apex-yearly-chart"></div>
                    <p class="tpfw-empty" data-empty="year" hidden><?php esc_html_e('No check-ins were recorded for this year and selection.', 'tickets-passes-for-woocommerce'); ?></p>
                </div>

                <p class="tpfw-scale">
                    <span><?php esc_html_e('Quiet', 'tickets-passes-for-woocommerce'); ?></span>
                    <span class="tpfw-scale__ramp" aria-hidden="true">
                        <i style="background:#eceff0"></i><i style="background:#cfe3d4"></i><i style="background:#93c5a1"></i><i style="background:#4e9a68"></i><i style="background:#2e7d32"></i><i style="background:#14532d"></i>
                    </span>
                    <span><?php esc_html_e('Busy', 'tickets-passes-for-woocommerce'); ?></span>
                </p>
            </section>

            <section class="tpfw-panel tpfw-panel--day" aria-busy="false">
                <div class="tpfw-panel__head">
                    <div class="tpfw-panel__heading">
                        <h2 class="tpfw-panel__title"><?php esc_html_e('Check-ins by hour', 'tickets-passes-for-woocommerce'); ?></h2>
                        <p class="tpfw-panel__day" data-day-label hidden></p>
                    </div>
                    <div class="tpfw-panel__tools">
                        <label class="screen-reader-text" for="analytics-day"><?php esc_html_e('Go to date', 'tickets-passes-for-woocommerce'); ?></label>
                        <input type="date" id="analytics-day" class="tpfw-select">
                        <button type="button" class="button tpfw-export" data-tpfw-export="day" disabled><?php esc_html_e('Export day', 'tickets-passes-for-woocommerce'); ?></button>
                    </div>
                </div>

                <div class="tpfw-panel__body">
                    <div class="tpfw-skeleton tpfw-skeleton--day" hidden></div>
                    <div id="apex-daily-chart"></div>
                    <p class="tpfw-empty" data-empty="day"><?php esc_html_e('Select a day above, or pick a date, to see when people arrived.', 'tickets-passes-for-woocommerce'); ?></p>
                    <p class="tpfw-empty" data-empty="day-none" hidden><?php esc_html_e('Nobody checked in on this day.', 'tickets-passes-for-woocommerce'); ?></p>
                </div>
            </section>
        </div>
    </div>
</div>
