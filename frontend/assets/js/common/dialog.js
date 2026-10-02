/* LQDialog — in-page replacement for the browser's alert() and confirm().
   Both return a Promise, so callers await the user's choice:

       if (!(await LQDialog.confirm({ title: 'Reset?', message: '...' }))) return;
       await LQDialog.alert({ title: 'Heads up', message: '...' });

   The dialog brings its own styles (light and dark), so a page only needs
   to load this file. */
const LQDialog = (function () {
    const STYLE_ID = 'lqDialogStyles';

    const CSS = `
        .lq-dialog {
            position: fixed;
            inset: 0;
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background-color: rgba(15, 23, 42, 0.55);
            font-family: inherit;
        }
        .lq-dialog-card {
            width: 100%;
            max-width: 400px;
            max-height: 90vh;
            overflow-y: auto;
            background-color: #fff;
            border-radius: 18px;
            padding: 26px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
            animation: lqDialogIn 0.18s ease;
        }
        @keyframes lqDialogIn {
            from { opacity: 0; transform: translateY(8px) scale(0.98); }
            to { opacity: 1; transform: none; }
        }
        @media (prefers-reduced-motion: reduce) {
            .lq-dialog-card { animation: none; }
        }
        .lq-dialog-icon {
            width: 52px;
            height: 52px;
            margin: 0 auto 14px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            background-color: #eff6ff;
            color: #2563eb;
        }
        .lq-dialog-icon.danger { background-color: #fef2f2; color: #dc2626; }
        .lq-dialog-title {
            margin: 0 0 8px;
            font-size: 1.05rem;
            font-weight: 800;
            color: #0f172a;
        }
        .lq-dialog-message {
            margin: 0;
            font-size: 0.85rem;
            line-height: 1.55;
            color: #475569;
            white-space: pre-line;
        }
        .lq-dialog-actions {
            display: flex;
            gap: 10px;
            margin-top: 22px;
        }
        .lq-dialog-btn {
            flex: 1;
            font-family: inherit;
            font-size: 0.84rem;
            font-weight: 700;
            padding: 11px 16px;
            border-radius: 10px;
            border: 1.5px solid transparent;
            cursor: pointer;
            transition: background-color 0.15s;
        }
        .lq-dialog-btn:focus-visible { outline: 2px solid #2563eb; outline-offset: 2px; }
        .lq-dialog-cancel { background-color: #f8fafc; border-color: #e2e8f0; color: #334155; }
        .lq-dialog-cancel:hover { background-color: #e2e8f0; }
        .lq-dialog-confirm { background-color: #2563eb; color: #fff; }
        .lq-dialog-confirm:hover { background-color: #1d4ed8; }
        .lq-dialog-confirm.danger { background-color: #dc2626; }
        .lq-dialog-confirm.danger:hover { background-color: #b91c1c; }

        body.dark .lq-dialog-card { background-color: #1e293b; }
        body.dark .lq-dialog-title { color: #f1f5f9; }
        body.dark .lq-dialog-message { color: #cbd5e1; }
        body.dark .lq-dialog-icon { background-color: rgba(37, 99, 235, 0.2); color: #93c5fd; }
        body.dark .lq-dialog-icon.danger { background-color: rgba(220, 38, 38, 0.18); color: #f87171; }
        body.dark .lq-dialog-cancel { background-color: #0f172a; border-color: #334155; color: #cbd5e1; }
        body.dark .lq-dialog-cancel:hover { background-color: #334155; }
    `;

    function ensureStyles() {
        if (document.getElementById(STYLE_ID)) return;
        const style = document.createElement('style');
        style.id = STYLE_ID;
        style.textContent = CSS;
        document.head.appendChild(style);
    }

    /* Builds the dialog with textContent only, so messages are never parsed as HTML. */
    function open({ title, message, confirmLabel, cancelLabel, danger, icon }) {
        ensureStyles();

        return new Promise((resolve) => {
            const previousFocus = document.activeElement;

            const overlay = document.createElement('div');
            overlay.className = 'lq-dialog';
            overlay.setAttribute('role', cancelLabel ? 'dialog' : 'alertdialog');
            overlay.setAttribute('aria-modal', 'true');

            const card = document.createElement('div');
            card.className = 'lq-dialog-card';

            const iconWrap = document.createElement('div');
            iconWrap.className = `lq-dialog-icon${danger ? ' danger' : ''}`;
            const iconEl = document.createElement('i');
            iconEl.className = `fas ${icon}`;
            iconWrap.appendChild(iconEl);

            const titleEl = document.createElement('h3');
            titleEl.className = 'lq-dialog-title';
            titleEl.id = 'lqDialogTitle';
            titleEl.textContent = title;
            overlay.setAttribute('aria-labelledby', titleEl.id);

            card.append(iconWrap, titleEl);

            if (message) {
                const messageEl = document.createElement('p');
                messageEl.className = 'lq-dialog-message';
                messageEl.textContent = message;
                card.appendChild(messageEl);
            }

            const actions = document.createElement('div');
            actions.className = 'lq-dialog-actions';

            const confirmBtn = document.createElement('button');
            confirmBtn.type = 'button';
            confirmBtn.className = `lq-dialog-btn lq-dialog-confirm${danger ? ' danger' : ''}`;
            confirmBtn.textContent = confirmLabel;

            let cancelBtn = null;
            if (cancelLabel) {
                cancelBtn = document.createElement('button');
                cancelBtn.type = 'button';
                cancelBtn.className = 'lq-dialog-btn lq-dialog-cancel';
                cancelBtn.textContent = cancelLabel;
                actions.appendChild(cancelBtn);
            }
            actions.appendChild(confirmBtn);
            card.appendChild(actions);
            overlay.appendChild(card);

            function close(result) {
                document.removeEventListener('keydown', onKeydown, true);
                overlay.remove();
                if (previousFocus && typeof previousFocus.focus === 'function') previousFocus.focus();
                resolve(result);
            }

            function onKeydown(e) {
                if (e.key === 'Escape') {
                    e.stopPropagation();
                    close(false);
                } else if (e.key === 'Tab') {
                    // Keep focus inside the dialog.
                    const buttons = cancelBtn ? [cancelBtn, confirmBtn] : [confirmBtn];
                    const index = buttons.indexOf(document.activeElement);
                    const next = e.shiftKey ? index - 1 : index + 1;
                    e.preventDefault();
                    buttons[(next + buttons.length) % buttons.length].focus();
                }
            }

            confirmBtn.addEventListener('click', () => close(true));
            cancelBtn?.addEventListener('click', () => close(false));
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) close(false);
            });
            document.addEventListener('keydown', onKeydown, true);

            document.body.appendChild(overlay);
            // A destructive action defaults to Cancel, like the browser's own dialog.
            (danger && cancelBtn ? cancelBtn : confirmBtn).focus();
        });
    }

    return {
        /** Resolves true when confirmed, false when cancelled or dismissed. */
        confirm({ title = 'Are you sure?', message = '', confirmLabel = 'Confirm', cancelLabel = 'Cancel', danger = false } = {}) {
            return open({
                title,
                message,
                confirmLabel,
                cancelLabel,
                danger,
                icon: danger ? 'fa-triangle-exclamation' : 'fa-circle-question',
            });
        },

        /** Resolves once the message is dismissed. */
        alert({ title = 'Notice', message = '', confirmLabel = 'OK' } = {}) {
            return open({ title, message, confirmLabel, cancelLabel: null, danger: false, icon: 'fa-circle-info' });
        },
    };
})();
