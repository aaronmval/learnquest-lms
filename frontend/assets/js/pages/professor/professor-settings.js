document.addEventListener('DOMContentLoaded', () => {

    /* SETTINGS TABS */
    (function initSettingsTabs() {
        const tabs = document.querySelectorAll('.settings-tab');
        const panels = document.querySelectorAll('.settings-panel');

        if (!tabs.length) return;

        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const targetId = tab.getAttribute('data-tab');

                tabs.forEach(t => {
                    t.classList.remove('active');
                    t.setAttribute('aria-selected', 'false');
                });
                tab.classList.add('active');
                tab.setAttribute('aria-selected', 'true');

                panels.forEach(panel => {
                    panel.classList.toggle('active', panel.id === `panel-${targetId}`);
                });
            });
        });
    })();


    /* PROFILE PHOTO (upload / preview / remove)*/
    (function initProfilePhoto() {
        const photoInput    = document.getElementById('profilePhotoInput');
        const photoEditBtn  = document.getElementById('profilePhotoEditBtn');
        const photoRemoveBtn = document.getElementById('profilePhotoRemoveBtn');
        const photoPreview  = document.getElementById('profilePhotoPreview');
        const photoInitials = document.getElementById('profilePhotoInitials');

        if (!photoInput) return;

        photoEditBtn?.addEventListener('click', () => photoInput.click());

        photoInput.addEventListener('change', () => {
            const file = photoInput.files && photoInput.files[0];
            if (!file) return;

            if (!file.type.startsWith('image/')) {
                showSettingsToast('Please choose an image file.', 'error');
                photoInput.value = '';
                return;
            }

            const reader = new FileReader();
            reader.onload = (e) => {
                photoPreview.src = e.target.result;
                photoPreview.style.display = 'block';
                photoInitials.style.display = 'none';

                /* Also update the header avatar with a photo */
                const headerAvatar = document.getElementById('headerAvatar');
                if (headerAvatar) {
                    headerAvatar.style.backgroundImage = `url('${e.target.result}')`;
                    headerAvatar.style.backgroundSize = 'cover';
                    headerAvatar.style.backgroundPosition = 'center';
                    headerAvatar.textContent = '';
                }

                showSettingsToast('Profile photo updated.', 'success');
            };
            reader.onerror = () => showSettingsToast('Could not read that image. Try again.', 'error');
            reader.readAsDataURL(file);
        });

        photoRemoveBtn?.addEventListener('click', () => {
            photoPreview.src = '';
            photoPreview.style.display = 'none';
            photoInitials.style.display = 'flex';
            photoInput.value = '';
            const headerAvatar = document.getElementById('headerAvatar');
            if (headerAvatar) {
                headerAvatar.style.backgroundImage = '';
                const firstName = document.getElementById('fieldFirstName');
                headerAvatar.textContent = (firstName?.value.trim().charAt(0) || 'M').toUpperCase();
            }

            showSettingsToast('Profile photo removed.', 'success');
        });
    })();


    /* PROFILE INFO FORM */
    (function initProfileInfoForm() {
        const form       = document.getElementById('profileInfoForm');
        const saveStatus = document.getElementById('profileInfoSaveStatus');

        const firstName  = document.getElementById('fieldFirstName');
        const lastName   = document.getElementById('fieldLastName');
        const middleName = document.getElementById('fieldMiddleName');
        const nickname   = document.getElementById('fieldNickname');

        const heroName          = document.getElementById('profileHeroName');
        const headerProfileName = document.getElementById('headerProfileName');
        const profileInitials   = document.getElementById('profilePhotoInitials');
        const headerAvatar      = document.getElementById('headerAvatar');

        if (!form) return;

        function updateLiveDisplayName() {
            const first  = (firstName?.value  || '').trim();
            const last   = (lastName?.value   || '').trim();
            const middle = (middleName?.value || '').trim();
            const middleInitial = middle ? `${middle.charAt(0)}.` : '';

            const fullName = [first, middleInitial, last].filter(Boolean).join(' ').trim();
            if (heroName && fullName) heroName.textContent = fullName;

            const nick = (nickname?.value || '').trim();
            if (headerProfileName) {
                headerProfileName.textContent = `Welcome, ${nick || first || 'Professor'}!`;
            }

            const initialChar = (first.charAt(0) || 'M').toUpperCase();

            /* Sidebar/profile-card initials */
            if (profileInitials) profileInitials.textContent = initialChar;

            /* Header avatar — only update text if no photo is set */
            if (headerAvatar && !headerAvatar.style.backgroundImage) {
                headerAvatar.textContent = initialChar;
            }
        }

        [firstName, lastName, middleName, nickname].forEach(field => {
            field?.addEventListener('input', updateLiveDisplayName);
        });

        form.addEventListener('submit', (e) => {
            e.preventDefault();

            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            updateLiveDisplayName();

            const submitBtn = form.querySelector('.settings-save-btn');
            setSettingsButtonSaving(submitBtn, true);

            setTimeout(() => {
                setSettingsButtonSaving(submitBtn, false);
                flashSettingsSaveStatus(saveStatus, 'Saved successfully.');
                showSettingsToast('Profile information updated.', 'success');
            }, 600);
        });
    })();


    /* NOTIFICATION PREFERENCES */
    (function initNotificationPrefs() {
        const saveBtn    = document.getElementById('notifSaveBtn');
        const saveStatus = document.getElementById('notifSaveStatus');

        if (!saveBtn) return;

        const toggleIds = [
            'notifSubmissions', 'notifEnrollment', 'notifGrading',
            'notifInsights', 'notifAdaptive',
            'notifEmail', 'notifSound'
        ];

        const stored = settingsSessionState.get('notifPrefs');
        if (stored) {
            toggleIds.forEach(id => {
                const el = document.getElementById(id);
                if (el && typeof stored[id] === 'boolean') el.checked = stored[id];
            });
        }

        saveBtn.addEventListener('click', () => {
            const prefs = {};
            toggleIds.forEach(id => {
                const el = document.getElementById(id);
                if (el) prefs[id] = el.checked;
            });

            setSettingsButtonSaving(saveBtn, true);

            setTimeout(() => {
                settingsSessionState.set('notifPrefs', prefs);
                setSettingsButtonSaving(saveBtn, false);
                flashSettingsSaveStatus(saveStatus, 'Preferences saved.');
                showSettingsToast('Notification preferences updated.', 'success');
            }, 500);
        });
    })();


    /* SECURITY: PASSWORD FORM */
    (function initPasswordForm() {
        const form       = document.getElementById('passwordForm');
        const saveStatus = document.getElementById('passwordSaveStatus');

        const currentPw  = document.getElementById('currentPassword');
        const newPw      = document.getElementById('newPassword');
        const confirmPw  = document.getElementById('confirmPassword');

        const strengthBar   = document.getElementById('passwordStrengthBar');
        const strengthFill  = strengthBar?.querySelector('.password-strength-fill');
        const strengthLabel = document.getElementById('passwordStrengthLabel');
        const matchHint     = document.getElementById('passwordMatchHint');

        if (!form) return;

        document.querySelectorAll('.password-toggle-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const targetId = btn.getAttribute('data-target');
                const input = document.getElementById(targetId);
                if (!input) return;

                const icon   = btn.querySelector('i');
                const isHidden = input.type === 'password';
                input.type   = isHidden ? 'text' : 'password';
                icon?.classList.toggle('fa-eye',       !isHidden);
                icon?.classList.toggle('fa-eye-slash',  isHidden);
                btn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
            });
        });

        function scorePassword(pw) {
            if (!pw) return 0;
            let score = 0;
            if (pw.length >= 8)  score++;
            if (pw.length >= 12) score++;
            if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) score++;
            if (/\d/.test(pw))            score++;
            if (/[^A-Za-z0-9]/.test(pw)) score++;
            return Math.min(score, 5);
        }

        function updateStrengthUI() {
            if (!strengthFill || !strengthLabel) return;
            const pw    = newPw.value;
            const score = scorePassword(pw);

            const levels = [
                { pct: 0,   label: '',             color: '#e2e8f0' },
                { pct: 20,  label: 'Very weak',     color: '#ef4444' },
                { pct: 40,  label: 'Weak',          color: '#f97316' },
                { pct: 60,  label: 'Fair',          color: '#eab308' },
                { pct: 80,  label: 'Strong',        color: '#22c55e' },
                { pct: 100, label: 'Very strong',   color: '#16a34a' }
            ];

            const level = levels[score];
            strengthFill.style.width           = `${level.pct}%`;
            strengthFill.style.backgroundColor = level.color;
            strengthLabel.textContent          = pw ? level.label : '';
            strengthLabel.style.color          = pw ? level.color : '#94a3b8';
        }

        function updateMatchUI() {
            if (!matchHint) return;
            const pw      = newPw.value;
            const confirm = confirmPw.value;

            matchHint.classList.remove('match', 'no-match');

            if (!confirm) { matchHint.textContent = ''; return; }

            if (pw === confirm) {
                matchHint.textContent = 'Passwords match.';
                matchHint.classList.add('match');
            } else {
                matchHint.textContent = 'Passwords do not match.';
                matchHint.classList.add('no-match');
            }
        }

        newPw?.addEventListener('input',    updateStrengthUI);
        newPw?.addEventListener('input',    updateMatchUI);
        confirmPw?.addEventListener('input', updateMatchUI);

        form.addEventListener('submit', (e) => {
            e.preventDefault();

            if (!form.checkValidity()) { form.reportValidity(); return; }

            const pw      = newPw.value;
            const confirm = confirmPw.value;
            const score   = scorePassword(pw);

            if (pw !== confirm) {
                showSettingsToast('New password and confirmation do not match.', 'error');
                confirmPw.focus();
                return;
            }

            if (score < 2) {
                showSettingsToast('Please choose a stronger password.', 'error');
                newPw.focus();
                return;
            }

            const submitBtn = form.querySelector('.settings-save-btn');
            setSettingsButtonSaving(submitBtn, true);

            setTimeout(() => {
                setSettingsButtonSaving(submitBtn, false);
                flashSettingsSaveStatus(saveStatus, 'Password updated.');
                showSettingsToast('Your password has been changed.', 'success');

                form.reset();
                if (strengthFill)  { strengthFill.style.width = '0%'; }
                if (strengthLabel) { strengthLabel.textContent = ''; }
                if (matchHint)     { matchHint.textContent = ''; matchHint.classList.remove('match', 'no-match'); }

                document.querySelectorAll('.password-input-wrap input').forEach(input => {
                    input.type = 'password';
                });
                document.querySelectorAll('.password-toggle-btn i').forEach(icon => {
                    icon.classList.add('fa-eye');
                    icon.classList.remove('fa-eye-slash');
                });
            }, 700);
        });
    })();


    /* ACTIVE SESSIONS — sign out a device*/
    (function initActiveSessions() {
        document.querySelectorAll('.session-revoke-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const row = btn.closest('.session-row');
                if (!row) return;

                const label = row.querySelector('.session-row-label')?.textContent || 'Device';
                btn.disabled    = true;
                btn.textContent = 'Signing out…';

                setTimeout(() => {
                    row.style.transition = 'opacity 0.25s, transform 0.25s';
                    row.style.opacity    = '0';
                    row.style.transform  = 'translateX(8px)';
                    setTimeout(() => row.remove(), 250);
                    showSettingsToast(`Signed out: ${label}`, 'success');
                }, 500);
            });
        });
    })();


    /* SIDEBAR-AWARE CONTENT WIDTH*/
    (function initSidebarAwareWidth() {
        const sidebar      = document.getElementById('sidebar');
        const bodyArea     = document.querySelector('.body-area');
        const settingsWrap = document.querySelector('.settings-wrap');

        if (!settingsWrap) return;

        function syncWidth() {
            const sidebarOpen = sidebar && !sidebar.classList.contains('collapsed');

            if (sidebarOpen) {
                settingsWrap.style.maxWidth = '';
            } else {
                settingsWrap.style.maxWidth = '100%';
            }
        }

        /* Run immediately on page load */
        syncWidth();

        /* Watch for class changes on sidebar, body-area, and body */
        const targets = [sidebar, bodyArea, document.body].filter(Boolean);
        const observer = new MutationObserver(syncWidth);
        targets.forEach(el => observer.observe(el, { attributes: true, attributeFilter: ['class'] }));
    })();


    /* SHARED HELPERS. */

    function setSettingsButtonSaving(btn, isSaving) {
        if (!btn) return;
        if (isSaving) {
            btn.dataset.originalHtml = btn.innerHTML;
            btn.disabled  = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
        } else {
            btn.disabled = false;
            if (btn.dataset.originalHtml) btn.innerHTML = btn.dataset.originalHtml;
        }
    }

    function flashSettingsSaveStatus(el, message) {
        if (!el) return;
        el.textContent = message;
        el.classList.add('show');
        clearTimeout(el._hideTimer);
        el._hideTimer = setTimeout(() => el.classList.remove('show'), 2500);
    }

    function showSettingsToast(message, type = 'info') {
        const toast        = document.getElementById('toast');
        const toastMessage = document.getElementById('toastMessage');
        if (!toast || !toastMessage) return;

        const icon = toast.querySelector('.toast-icon i');
        if (icon) {
            icon.className = type === 'success'
                ? 'fas fa-circle-check'
                : type === 'error'
                    ? 'fas fa-circle-exclamation'
                    : 'fas fa-info-circle';
        }

        toastMessage.textContent = message;
        toast.classList.add('show');
        toast.classList.add('visible');

        clearTimeout(toast._hideTimer);
        toast._hideTimer = setTimeout(() => {
            toast.classList.remove('show');
            toast.classList.remove('visible');
        }, 3000);
    }

    const settingsSessionState = {
        _data: {},
        get(key)        { return this._data[key]; },
        set(key, value) { this._data[key] = value; }
    };

});
