/**
 * BurrowSync for Farcaster — admin behaviour.
 *
 * - Receives the SIWN (Sign In With Neynar) success callback and hands
 *   the signer_uuid to the server, which verifies it with Neynar before
 *   storing anything (the browser is untrusted input).
 * - "Create webhook" button.
 * - Per-post "Retry now".
 * - Disconnect signer.
 */
(function ($) {
    'use strict';

    /**
     * SIWN success callback (referenced by the data-success-callback
     * attribute on the settings page button). Global by necessity.
     *
     * @param {{signer_uuid: string, fid: number}} data
     */
    window.bsyncFcOnSignInSuccess = function (data) {
        if (!data || !data.signer_uuid) {
            window.alert(bsyncFcAdmin.strings.error);
            return;
        }
        $.post(bsyncFcAdmin.ajaxUrl, {
            action: 'bsync_fc_connect_signer',
            nonce: bsyncFcAdmin.nonce,
            signer_uuid: data.signer_uuid
        }).done(function (response) {
            if (response && response.success) {
                window.location.reload();
            } else {
                window.alert((response && response.data) || bsyncFcAdmin.strings.error);
            }
        }).fail(function () {
            window.alert(bsyncFcAdmin.strings.error);
        });
    };

    /**
     * Fallback when Neynar redirects back with credentials in the URL
     * (Continue button path) instead of closing the popup via postMessage.
     */
    function trySiwnRedirectReturn() {
        var params = new URLSearchParams(window.location.search);
        var signerUuid = params.get('signer_uuid') || '';
        if (!signerUuid && window.location.hash) {
            var hashParams = new URLSearchParams(window.location.hash.replace(/^#/, ''));
            signerUuid = hashParams.get('signer_uuid') || '';
        }
        if (!signerUuid) {
            return;
        }
        // Server-side handle_siwn_redirect() normally catches GET params
        // first; this covers hash-only returns and races.
        if (window.history && window.history.replaceState) {
            window.history.replaceState({}, document.title, window.location.pathname + '?page=burrowsync-for-farcaster');
        }
        window.bsyncFcOnSignInSuccess({ signer_uuid: signerUuid, fid: params.get('fid') });
    }

    /**
     * Neynar's SIWN script injects <button id="siwn-button"> without
     * type="button". The default button type is "submit", and the SIWN
     * div is rendered inside the settings <form> — so clicking it would
     * open the popup AND submit the form, replacing this page. That
     * destroys the window Neynar postMessages the credentials back to,
     * making the popup's final "Continue" button appear to do nothing.
     * Force type="button" as soon as the button exists.
     */
    function neuterSiwnSubmit() {
        var btn = document.getElementById('siwn-button');
        if (btn) {
            btn.type = 'button';
            return;
        }
        var target = document.querySelector('.neynar_signin');
        if (!target || typeof MutationObserver === 'undefined') {
            return;
        }
        new MutationObserver(function (mutations, observer) {
            var b = document.getElementById('siwn-button');
            if (b) {
                b.type = 'button';
                observer.disconnect();
            }
        }).observe(target, { childList: true, subtree: true });
    }

    $(function () {
        trySiwnRedirectReturn();
        neuterSiwnSubmit();

        // Belt and braces: even if the observer misses, stop the click
        // from submitting the surrounding form. The SIWN popup is opened
        // by the button's own onclick, which still runs.
        $(document).on('click', '#siwn-button', function (e) {
            e.preventDefault();
        });

        $('#bsync-fc-create-webhook').on('click', function () {
            var $button = $(this);
            var $result = $('#bsync-fc-webhook-result');
            $button.prop('disabled', true);
            $result.text(bsyncFcAdmin.strings.working);

            $.post(bsyncFcAdmin.ajaxUrl, {
                action: 'bsync_fc_create_webhook',
                nonce: bsyncFcAdmin.nonce
            }).done(function (response) {
                if (response && response.success) {
                    $result.text(response.data.message);
                    window.location.reload();
                } else {
                    $result.text((response && response.data) || bsyncFcAdmin.strings.error);
                    $button.prop('disabled', false);
                }
            }).fail(function () {
                $result.text(bsyncFcAdmin.strings.error);
                $button.prop('disabled', false);
            });
        });

        $(document).on('click', '.bsync-fc-retry', function () {
            var $button = $(this);
            var $result = $button.siblings('.bsync-fc-retry-result');
            $button.prop('disabled', true).text(bsyncFcAdmin.strings.working);

            $.post(bsyncFcAdmin.ajaxUrl, {
                action: 'bsync_fc_retry_post',
                nonce: bsyncFcAdmin.nonce,
                post_id: $button.data('post-id')
            }).done(function (response) {
                if (response && response.success) {
                    $result.text(response.data.message);
                    window.location.reload();
                } else {
                    $result.text((response && response.data) || bsyncFcAdmin.strings.error);
                    $button.prop('disabled', false).text(bsyncFcAdmin.strings.retry);
                }
            }).fail(function () {
                $result.text(bsyncFcAdmin.strings.error);
                $button.prop('disabled', false).text(bsyncFcAdmin.strings.retry);
            });
        });

        $('.bsync-fc-disconnect').on('click', function () {
            if (!window.confirm(bsyncFcAdmin.strings.confirmDisconnect)) {
                return;
            }
            $.post(bsyncFcAdmin.ajaxUrl, {
                action: 'bsync_fc_disconnect_signer',
                nonce: bsyncFcAdmin.nonce
            }).always(function () {
                window.location.reload();
            });
        });
    });
})(jQuery);
