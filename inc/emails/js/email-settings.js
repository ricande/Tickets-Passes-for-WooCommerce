/**
 * Email settings screen.
 *
 * Each email is a collapsible panel holding a Subject field and a TinyMCE body, plus the list
 * of merge tags that email supports. Clicking a tag inserts it at the cursor and copies it, so
 * it also works when the target is a field the click cannot reach. Saving posts every panel at
 * once and reports back in an inline notice rather than reloading - the editors hold unsaved
 * content and a reload would be the wrong instinct here.
 */
jQuery(document).ready(function($)
{
    // Reuses WordPress's own metabox collapse/persist mechanism (same one used on every
    // post-edit screen) instead of a custom accordion widget.
    if(typeof postboxes !== 'undefined')
    {
        postboxes.add_postbox_toggles('tpfw-email-settings');
    }

    // Remembers which Subject input or TinyMCE editor was last active inside each panel, so
    // clicking a tag can insert it exactly where the cursor was left - a tag click itself
    // always steals focus first, so by the time the click fires it's already too late to
    // read "the currently focused field" directly.
    $('.email-setting-form .email-input').on('focus', function()
    {
        $(this).closest('.email-setting-form').data('tpfw-last-field', { type: 'input', el: this });
    });

    if(typeof tinymce !== 'undefined')
    {
        tinymce.on('AddEditor', function(e)
        {
            e.editor.on('focus', function()
            {
                $(e.editor.getElement()).closest('.email-setting-form').data('tpfw-last-field', { type: 'tinymce', id: e.editor.id });
            });
        });
    }

    $('.tpfw-merge-tag').on('click', function()
    {
        var $tag       = $(this);
        var sTag       = $tag.attr('data-tag');
        var oLastField = $tag.closest('.email-setting-form').data('tpfw-last-field');

        /**
         * Briefly swaps the tag's own label for "Copied!" as the only feedback that anything
         * happened - a clipboard write is otherwise invisible.
         *
         * @returns {void}
         */
        function flashCopied()
        {
            var sOriginalText = $tag.text();
            $tag.addClass('tpfw-merge-tag-copied').text(tpfwParamsEmailSettings.translations.sCopied);
            setTimeout(function()
            {
                $tag.removeClass('tpfw-merge-tag-copied').text(sOriginalText);
            }, 1200);
        }

        /**
         * Copies the clicked merge tag to the clipboard.
         *
         * @returns {void}
         */
        function copyToClipboard()
        {
            // navigator.clipboard needs a secure (https) context - WooCommerce sites still
            // commonly run on plain http (like this one), where it's simply undefined. Falls
            // back to the older execCommand approach so copying still works there.
            if(navigator.clipboard && navigator.clipboard.writeText)
            {
                navigator.clipboard.writeText(sTag).then(flashCopied);
            }
            else
            {
                var $tempInput = $('<textarea>').val(sTag).css({ position: 'fixed', top: 0, left: 0, opacity: 0 }).appendTo('body');
                $tempInput[0].select();
                document.execCommand('copy');
                $tempInput.remove();
                flashCopied();
            }
        }

        if(oLastField && oLastField.type === 'input')
        {
            var el     = oLastField.el;
            var iStart = el.selectionStart != null ? el.selectionStart : el.value.length;
            var iEnd   = el.selectionEnd != null ? el.selectionEnd : el.value.length;
            el.value   = el.value.slice(0, iStart) + sTag + el.value.slice(iEnd);
            el.focus();
            el.setSelectionRange(iStart + sTag.length, iStart + sTag.length);
        }
        else if(oLastField && oLastField.type === 'tinymce' && typeof tinyMCE !== 'undefined' && tinyMCE.get(oLastField.id))
        {
            tinyMCE.get(oLastField.id).execCommand('mceInsertContent', false, sTag);
            tinyMCE.get(oLastField.id).focus();
        }

        copyToClipboard();
    });

    /**
     * Current content of one wp_editor() field.
     *
     * Reads the TinyMCE instance only while it is the active view. When the editor is on its
     * "Text" tab, or the user has disabled the visual editor altogether (no instance at all),
     * the textarea is the source of truth - reading TinyMCE there returned stale content or
     * threw and silently broke the save.
     *
     * @param {string} sID Editor id.
     * @returns {string}
     */
    function editorContent(sID)
    {
        var oEditor = window.tinyMCE ? tinyMCE.get(sID) : null;
        if(oEditor && !oEditor.isHidden()) return oEditor.getContent();
        return $('#' + sID).val() || '';
    }

    $('.tpfw-admin-email-settings-save').on('click', function()
    {
        var oThis      = $(this);
        var oThisPanel = oThis.closest('.wrap');
        var $notice    = $('.tpfw-email-settings-save-notice');

        var data =
        {
            action                                 : 'tpfw_ajax_save_tpfw_email_settings',
            security                               : tpfwParamsEmailSettings.aNonces.ajax_save_tpfw_email_settings,
            wc_confirmation_email_message          : editorContent('email-setting-wc-confirmation-message'),
            wc_completed_email_message             : editorContent('email-setting-wc-completed-message'),

            resend_ticket_email_subject            : $('.email-setting-resend-ticket-subject').val(),
            resend_ticket_email_message            : editorContent('email-setting-resend-ticket-message'),

            resend_pass_email_subject              : $('.email-setting-resend-pass-subject').val(),
            resend_pass_email_message              : editorContent('email-setting-resend-pass-message'),

            gifted_pass_email_new_user_subject     : $('.email-setting-gifted-pass-new-user-subject').val(),
            gifted_pass_email_new_user_message     : editorContent('email-setting-gifted-pass-new-user-message'),

            gifted_pass_email_existing_user_subject: $('.email-setting-gifted-pass-existing-user-subject').val(),
            gifted_pass_email_existing_user_message: editorContent('email-setting-gifted-pass-existing-user-message'),
        }

        oThis.css('pointer-events', 'none');
        oThisPanel.css('pointer-events', 'none');
        oThis.fadeTo('fast', 0.2);
        oThisPanel.fadeTo('fast', 0.2);
        $notice.removeClass('tpfw-notice-success tpfw-notice-error').text('');

        $.ajax({
            url: ajaxurl,
            data: data,
            dataType: 'JSON',
            method: 'POST',
        })
        .done(function(oResult)
        {
            if(oResult.success == true)
            {
                $notice.addClass('tpfw-notice-success').text(tpfwParamsEmailSettings.translations.sSaved);
            }
            else
            {
                $notice.addClass('tpfw-notice-error').text(oResult.data.sMessage || tpfwParamsEmailSettings.translations.sSaveFailed);
            }
        })
        .fail(function()
        {
            $notice.addClass('tpfw-notice-error').text(tpfwParamsEmailSettings.translations.sSaveFailed);
        })
        .always(function()
        {
            oThis.css('pointer-events', 'auto');
            oThisPanel.css('pointer-events', 'auto');
            oThis.fadeTo('450', 1);
            oThisPanel.fadeTo('450', 1);
        });
    })
})
