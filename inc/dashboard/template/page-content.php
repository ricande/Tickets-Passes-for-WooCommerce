<?php
/**
 * Page wrapper shared by the Tickets, Timeslot Tickets and Passes screens.
 *
 * Expects $oTable (a TPFW_Dashboard_Table), $sPageTitle and $sSlug from TPFW_Dashboard::page_content().
 * The whole table sits in one GET form so the search box, the status filter, the sortable
 * headings and the bulk-action checkboxes all submit together.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="wrap tpfw-dashboard">
    <div class="tpfw-dashboard-header">
        <h1><?php echo esc_html($sPageTitle); ?></h1>
    </div>
    <?php
        // The Download CSV button is printed by the table's own extra_tablenav(), where it sits
        // on the same row as the status filter and the search box.
        $oTable->prepare_items();
        $oTable->print_bulk_notice();
    ?>
    <form method="get" class="tpfw-dashboard-table">
        <input type="hidden" name="page" value="<?php echo esc_attr($sSlug); ?>">
        <?php $oTable->display(); ?>
    </form>
    <?php if($bSupportsTransfer): ?>
    <!-- Opened by the Transfer row action (dashboard.js); native <dialog> so it needs no library. -->
    <dialog id="tpfw-transfer-dialog" class="tpfw-dialog">
        <form method="dialog" class="tpfw-dialog-form" id="tpfw-transfer-form">
            <h2><?php esc_html_e('Transfer to another customer', 'tickets-passes-for-woocommerce'); ?></h2>
            <p><?php esc_html_e('The new holder must already have an account on this site. They will be emailed the QR code, and a note is added to the original order.', 'tickets-passes-for-woocommerce'); ?></p>
            <label for="tpfw-transfer-recipient"><?php esc_html_e('Email address or user ID', 'tickets-passes-for-woocommerce'); ?></label>
            <input type="text" id="tpfw-transfer-recipient" name="recipient" autocomplete="off" required>
            <p class="tpfw-dialog-error" hidden></p>
            <div class="tpfw-dialog-actions">
                <button type="button" class="button" value="cancel"><?php esc_html_e('Cancel', 'tickets-passes-for-woocommerce'); ?></button>
                <button type="submit" class="button button-primary"><?php esc_html_e('Transfer', 'tickets-passes-for-woocommerce'); ?></button>
            </div>
        </form>
    </dialog>
    <?php endif; ?>
</div>
