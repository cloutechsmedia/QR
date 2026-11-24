/**
 * Techzo Kochi Scratch & Win - Admin Panel Scripts
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', () => {
        // Attach confirmation prompt to any form/button with data-confirm
        document.querySelectorAll('[data-confirm]').forEach((el) => {
            const eventName = el.tagName === 'FORM' ? 'submit' : 'click';
            el.addEventListener(eventName, (evt) => {
                const msg = el.getAttribute('data-confirm') || 'Are you sure?';
                if (!window.confirm(msg)) {
                    evt.preventDefault();
                }
            });
        });
    });
})();
