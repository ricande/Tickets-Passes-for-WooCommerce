<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- this template is include()d inside a class method, so every variable in it is method-scope, not global.
/**
 * The Tickets tab on the WooCommerce My Account page.
 *
 * Rendered from TPFW_Ticket_WC_MyAccount, so $this is that class. Plain tickets and timeslot
 * tickets are listed together, newest first, which is why the two arrays it passes in are merged
 * and re-sorted here rather than queried as one.
 *
 * ticket-wc-myaccount.js binds to the classes below by name, so renaming one here disables the
 * matching handler.
 */
$aTicketsMerged = array_merge($aUserTickets, $aUserTimeslotTickets);

usort($aTicketsMerged, function($a, $b)
{
    return strtotime($b->created) - strtotime($a->created);
});

// The check-in count used to be a COUNT(*) per row inside the render loop below, so a
// customer holding a full page of tickets paid for twenty extra queries to draw one tab.
// Both stats tables are read once here instead, grouped, and looked up by nano id.
global $wpdb;
$aNanoIDs       = array('ticket' => array(), 'timeslot' => array());
$aCheckinCounts = array('ticket' => array(), 'timeslot' => array());
foreach($aTicketsMerged as $oTicketRow)
{
    // Only rows from the timeslot_tickets table carry a timeslot_id, and each table keeps
    // its check-ins in its own stats table.
    $aNanoIDs[isset($oTicketRow->timeslot_id) ? 'timeslot' : 'ticket'][] = $oTicketRow->nano_id;
}
foreach(array('ticket' => 'tpfw_tickets_stats', 'timeslot' => 'tpfw_timeslot_tickets_stats') as $sTicketType => $sStatsTable)
{
    if(empty($aNanoIDs[$sTicketType])) { continue; }

    $sNanoIdsPlaceholders = implode(', ', array_fill(0, count($aNanoIDs[$sTicketType]), '%s'));
    $sCheckinCountsSQL    = $wpdb->prepare(
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $sNanoIdsPlaceholders is a "%s, %s, ..." scaffold, not user data; the values are bound via array_merge() below.
        "SELECT nano_id_fk, COUNT(*) AS used_count FROM %i WHERE deleted IS NULL AND nano_id_fk IN ($sNanoIdsPlaceholders) GROUP BY nano_id_fk",
        array_merge(array($wpdb->prefix.$sStatsTable), $aNanoIDs[$sTicketType])
    );
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sCheckinCountsSQL is the return value of $wpdb->prepare() above.
    $aCheckinCounts[$sTicketType] = wp_list_pluck($wpdb->get_results($sCheckinCountsSQL), 'used_count', 'nano_id_fk');
}
?>
<div class="tpfw-tickets-page">
    <div class="tpfw-tickets-header">
        <h1><?php echo esc_html__('Tickets', 'tickets-passes-for-woocommerce'); ?></h1>
        <?php if(!empty($aTicketsMerged)) : ?>
            <p class="tpfw-tickets-lede"><?php echo esc_html__('Show the QR code at the door, or download a printable copy.', 'tickets-passes-for-woocommerce'); ?></p>
        <?php endif; ?>
    </div>

    <?php if(empty($aTicketsMerged)) : ?>

        <div class="tpfw-tickets-empty">
            <svg class="tpfw-tickets-empty__icon" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path d="M3 8.5A1.5 1.5 0 0 1 4.5 7h15A1.5 1.5 0 0 1 21 8.5v1.75a1.75 1.75 0 0 0 0 3.5v1.75a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 15.5v-1.75a1.75 1.75 0 0 0 0-3.5V8.5Z"/>
                <path d="M9.5 7v10" stroke-dasharray="2.5 2.5"/>
            </svg>
            <p class="tpfw-tickets-empty__text"><?php echo esc_html__("You don't have any tickets yet.", 'tickets-passes-for-woocommerce'); ?></p>
            <?php if(function_exists('wc_get_page_permalink')) : ?>
                <a class="tpfw-tickets-empty__cta" href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>"><?php echo esc_html__('Browse tickets', 'tickets-passes-for-woocommerce'); ?></a>
            <?php endif; ?>
        </div>

    <?php else : ?>

        <ul class="tpfw-tickets-grid">
            <?php
                foreach($aTicketsMerged as $iUserTicketKey => $oUserTicket)
                {
                    $oProduct        = wc_get_product($oUserTicket->product_id);
                    if(empty($oProduct)) continue;
                    $sProductName    = $oProduct ? $oProduct->get_name() : __('No Variation Found', 'tickets-passes-for-woocommerce');
                    $iProductPrice   = $oProduct ? $oProduct->get_price() : 0;
                    $sFormattedPrice = $iProductPrice ? wc_price($iProductPrice) : __('No Price Found', 'tickets-passes-for-woocommerce');
                    $sDateTimeFormat = $this->oFunctions->get_datetime_format('datetime');
                    $sValidFrom      = gmdate($sDateTimeFormat, strtotime($oUserTicket->valid_from));
                    $sValidTo        = gmdate($sDateTimeFormat, strtotime($oUserTicket->valid_to));
                    // Only rows from the timeslot_tickets table carry a timeslot_id - a regular
                    // Ticket's dates are a purchase-based validity window ("Valid from/to"), while
                    // a Timeslot Ticket's are a specific reserved event time ("Start"/"End").
                    $bIsTimeslotTicket = isset($oUserTicket->timeslot_id);
                    $sValidFromLabel = $bIsTimeslotTicket ? __('Start', 'tickets-passes-for-woocommerce') : __('Valid from', 'tickets-passes-for-woocommerce');
                    $sValidToLabel   = $bIsTimeslotTicket ? __('End', 'tickets-passes-for-woocommerce') : __('Valid to', 'tickets-passes-for-woocommerce');
                    // Counts this ticket's check-ins so a fully-used ticket can be told apart
                    // from one that's simply still within its valid window (mirrors the admin
                    // dashboard's own logic). Pre-fetched above the loop, keyed by nano id.
                    $aTypeCheckinCounts = $aCheckinCounts[$bIsTimeslotTicket ? 'timeslot' : 'ticket'];
                    $iCheckinCount      = isset($aTypeCheckinCounts[$oUserTicket->nano_id]) ? (int)$aTypeCheckinCounts[$oUserTicket->nano_id] : 0;
                    $iUsesLeft          = max(0, (int)$oUserTicket->max_uses - $iCheckinCount);

                    $sStatusText     = "";
                    $sStatusModifier = "";
                    if($oUserTicket->deleted != null && $oUserTicket->deleted != "")
                    {
                        $sStatusText 		= __('Cancelled', 'tickets-passes-for-woocommerce');
                        $sStatusModifier	= 'cancelled';
                    }
                    else if($iCheckinCount >= (int)$oUserTicket->max_uses)
                    {
                        $sStatusText 		= __('Used', 'tickets-passes-for-woocommerce');
                        $sStatusModifier	= 'used';
                    }
                    else if(strtotime($oUserTicket->valid_to) < current_time('timestamp'))
                    {
                        $sStatusText 		= __('Expired', 'tickets-passes-for-woocommerce');
                        $sStatusModifier	= 'expired';
                    }
                    else if(strtotime($oUserTicket->valid_from) > current_time('timestamp'))
                    {
                        $sStatusText 		= __('Not valid yet', 'tickets-passes-for-woocommerce');
                        $sStatusModifier	= 'pending';
                    }
                    else
                    {
                        $sStatusText 		= __('Active', 'tickets-passes-for-woocommerce');
                        $sStatusModifier	= 'active';
                    }

                    // Timeslot tickets follow the Accent Color set on the Timeslot Ticket settings
                    // tab; regular tickets follow the one set on the Ticket tab - each card gets
                    // its own override rather than one page-wide default, since both product
                    // types share this same "Tickets" page.
                    if($bIsTimeslotTicket)
                    {
                        $sTicketCardStyle = sprintf('--tpfw-ticket-accent:%s;--tpfw-ticket-accent-soft:%s;', esc_attr($sTimeslotColorAccent), esc_attr($sTimeslotColorAccentSoft));
                    }
                    else
                    {
                        $sTicketCardStyle = sprintf('--tpfw-ticket-accent:%s;--tpfw-ticket-accent-soft:%s;', esc_attr($sTicketColorAccent), esc_attr($sTicketColorAccentSoft));
                    }
                    ?>
                        <li class="ticket-container tpfw-ticket-card" data-attr-nanoid="<?php echo esc_attr($oUserTicket->nano_id); ?>" data-attr-rowid="<?php echo esc_attr($oUserTicket->id); ?>" style="<?php echo esc_attr($sTicketCardStyle); ?>">
                            <div class="tpfw-ticket-card-stub">
                                <div class="tpfw-ticket-card-qr">
                                    <?php /* translators: %s: name of the product the QR code belongs to. */ ?>
                                    <img src="<?php echo esc_url($this->oFunctions->get_file_url('qr', $oUserTicket->nano_id, 'webp', true)); ?>" alt="<?php echo esc_attr(sprintf(__('QR code for %s', 'tickets-passes-for-woocommerce'), $sProductName)); ?>" width="112" height="112">
                                </div>
                                <span class="tpfw-status-pill tpfw-status-pill--<?php echo esc_attr($sStatusModifier); ?>"><?php echo esc_html($sStatusText); ?></span>
                            </div>

                            <div class="tpfw-ticket-card-divider" aria-hidden="true"></div>

                            <div class="tpfw-ticket-card-body">
                                <div class="tpfw-ticket-card-top">
                                    <svg class="tpfw-ticket-card-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path d="M3 8.5A1.5 1.5 0 0 1 4.5 7h15A1.5 1.5 0 0 1 21 8.5v1.75a1.75 1.75 0 0 0 0 3.5v1.75a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 15.5v-1.75a1.75 1.75 0 0 0 0-3.5V8.5Z"/>
                                    </svg>
                                    <span class="variation-name"><?php echo esc_html($sProductName); ?></span>
                                </div>

                                <div class="price"><?php echo wp_kses_post($sFormattedPrice); ?></div>

                                <dl class="valid-dates">
                                    <div class="valid-from">
                                        <dt><?php echo esc_html($sValidFromLabel); ?></dt>
                                        <dd><?php echo esc_html($sValidFrom); ?></dd>
                                    </div>
                                    <div class="valid-to">
                                        <dt><?php echo esc_html($sValidToLabel); ?></dt>
                                        <dd><?php echo esc_html($sValidTo); ?></dd>
                                    </div>
                                    <div class="uses-left">
                                        <dt><?php echo esc_html__('Uses left', 'tickets-passes-for-woocommerce'); ?></dt>
                                        <dd><?php echo esc_html($iUsesLeft); ?></dd>
                                    </div>
                                </dl>

                                <?php $sProductNote = $this->oFunctions->get_product_note($oUserTicket->product_id); ?>
                                <?php if($sProductNote != '') : ?>
                                    <p class="tpfw-ticket-card-note"><?php echo nl2br(esc_html($sProductNote)); ?></p>
                                <?php endif; ?>

                                <?php if(extension_loaded('mbstring')) : ?>
                                    <div class="download-ticket-wrapper">
                                        <button type="button" class="download-ticket-pdf-btn">
                                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                <path d="M12 4v11m0 0 4-4m-4 4-4-4M5 19h14"/>
                                            </svg>
                                            <?php echo esc_html__('Download', 'tickets-passes-for-woocommerce'); ?>
                                        </button>
                                        <?php if($bIsTimeslotTicket) : ?>
                                            <a class="tpfw-add-to-calendar-btn" href="<?php echo esc_url(rest_url('tpfw/v1/timeslot-ticket/ics/'.$oUserTicket->nano_id)); ?>">
                                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                    <rect x="3.5" y="5" width="17" height="15" rx="2"/>
                                                    <path d="M3.5 9.5h17M8 3v3.5M16 3v3.5M12 13v4.2M9.9 15.1h4.2"/>
                                                </svg>
                                                <?php echo esc_html__('Add to Calendar', 'tickets-passes-for-woocommerce'); ?>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php
                }
            ?>
        </ul>

    <?php endif; ?>
</div>
