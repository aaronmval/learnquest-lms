/* SETTINGS PAGE — shared by student-settings.html and professor-settings.html.
   Everything is loaded from and saved to /settings for the signed-in user;
   the role (and therefore which toggles and classes apply) comes from the server. */
document.addEventListener('DOMContentLoaded', () => {
    let settings = null;

    /* HTTP */
    function getCsrfToken() {
        const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
        return match ? decodeURIComponent(match[1]) : '';
    }

    async function api(url, { method = 'GET', json = null, formData = null } = {}) {
        const headers = { Accept: 'application/json' };
        if (method !== 'GET') headers['X-XSRF-TOKEN'] = getCsrfToken();
        if (json !== null) headers['Content-Type'] = 'application/json';

        const res = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers,
            body: json !== null ? JSON.stringify(json) : formData,
        });

        let data = null;
        try {
            data = await res.json();
        } catch (e) {
            /* Empty or non-JSON body. */
        }

        if (!res.ok) {
            const error = new Error(firstError(data) || 'Something went wrong. Please try again.');
            error.status = res.status;
            throw error;
        }
        return data;
    }

    function firstError(data) {
        if (data?.errors) {
            const first = Object.values(data.errors)[0];
            if (Array.isArray(first) && first.length) return first[0];
        }
        return data?.message || null;
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    /* Keep the shell's navbar (outside this iframe) in sync. */
    function syncNavbar(changes) {
        try {
            window.parent?.LQ_updateProfile?.(changes);
        } catch (e) {
            /* Not inside the shell — nothing to update. */
        }
    }

    /* SETTINGS TABS */
    (function initSettingsTabs() {
        const tabs = document.querySelectorAll('.settings-tab');
        const panels = document.querySelectorAll('.settings-panel');

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

    /* LOAD */
    async function loadSettings() {
        try {
            settings = await api('/settings');
        } catch (e) {
            showToast('Could not load your settings. Please refresh the page.', 'error');
            return;
        }

        renderProfile();
        renderPreferences();
        loadClasses();
    }

    /* PROFILE CARD + PROFILE FORM */
    function renderProfile() {
        const name = settings.name || '';

        const nameInput = document.getElementById('fieldName');
        const emailInput = document.getElementById('fieldEmail');
        if (nameInput) nameInput.value = name;
        if (emailInput) emailInput.value = settings.email || '';

        const heroName = document.getElementById('profileHeroName');
        const heroMeta = document.getElementById('profileHeroMeta');
        if (heroName) heroName.textContent = name;
        if (heroMeta) heroMeta.textContent = settings.email || '';

        renderPhoto();
    }

    function renderPhoto() {
        const preview = document.getElementById('profilePhotoPreview');
        const initials = document.getElementById('profilePhotoInitials');
        const removeBtn = document.getElementById('profilePhotoRemoveBtn');
        const hasPhoto = Boolean(settings.avatar_url);

        if (preview) {
            if (hasPhoto) preview.src = settings.avatar_url;
            else preview.removeAttribute('src');
            preview.style.display = hasPhoto ? '' : 'none';
        }
        if (initials) {
            initials.textContent = (settings.name || '?').trim().charAt(0).toUpperCase();
            initials.style.display = hasPhoto ? 'none' : '';
        }
        if (removeBtn) removeBtn.hidden = !hasPhoto;
    }

    (function initProfileInfoForm() {
        const form = document.getElementById('profileInfoForm');
        const saveStatus = document.getElementById('profileInfoSaveStatus');
        const nameInput = document.getElementById('fieldName');
        if (!form || !nameInput) return;

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            const name = nameInput.value.trim();
            if (!name) {
                showToast('Please enter your name.', 'error');
                nameInput.focus();
                return;
            }

            const submitBtn = form.querySelector('.settings-save-btn');
            setButtonSaving(submitBtn, true);

            try {
                settings = await api('/settings/profile', { method: 'PUT', json: { name } });
                renderProfile();
                syncNavbar({ name: settings.name });
                flashSaveStatus(saveStatus, 'Saved.');
                showToast('Profile updated.', 'success');
            } catch (err) {
                showToast(err.message, 'error');
            } finally {
                setButtonSaving(submitBtn, false);
            }
        });
    })();

    /* PROFILE PHOTO (upload / remove) */
    (function initProfilePhoto() {
        const input = document.getElementById('profilePhotoInput');
        const editBtn = document.getElementById('profilePhotoEditBtn');
        const removeBtn = document.getElementById('profilePhotoRemoveBtn');
        const MAX_BYTES = 2 * 1024 * 1024;

        editBtn?.addEventListener('click', () => input?.click());

        input?.addEventListener('change', async () => {
            const file = input.files?.[0];
            input.value = '';
            if (!file) return;

            if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) {
                showToast('Please choose a PNG, JPG, or WEBP image.', 'error');
                return;
            }
            if (file.size > MAX_BYTES) {
                showToast('The photo must be 2 MB or smaller.', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('photo', file);
            if (editBtn) editBtn.disabled = true;

            try {
                settings = await api('/settings/avatar', { method: 'POST', formData });
                renderPhoto();
                syncNavbar({ avatarUrl: settings.avatar_url });
                showToast('Profile photo updated.', 'success');
            } catch (err) {
                showToast(err.message, 'error');
            } finally {
                if (editBtn) editBtn.disabled = false;
            }
        });

        removeBtn?.addEventListener('click', async () => {
            removeBtn.disabled = true;
            try {
                settings = await api('/settings/avatar', { method: 'DELETE' });
                renderPhoto();
                syncNavbar({ avatarUrl: null });
                showToast('Profile photo removed.', 'success');
            } catch (err) {
                showToast(err.message, 'error');
            } finally {
                removeBtn.disabled = false;
            }
        });
    })();

    /* MY CLASSES (read-only) */
    async function loadClasses() {
        const list = document.getElementById('myClassesList');
        if (!list) return;

        const isProfessor = settings.role === 'professor';

        try {
            const classes = await api(isProfessor ? '/professor/classes' : '/student/classes');

            if (!classes.length) {
                list.innerHTML = `<p class="settings-field-hint">${
                    isProfessor
                        ? "You aren't teaching any active classes yet."
                        : "You haven't joined any classes yet. Use a class code from your teacher to join one."
                }</p>`;
                return;
            }

            list.innerHTML = classes
                .map((c) => {
                    const title = c.subject || c.name;
                    const details = [c.subject ? c.name : null, c.section ? `Section ${c.section}` : null];
                    if (isProfessor) {
                        const n = c.students_count ?? 0;
                        details.push(`${n} student${n === 1 ? '' : 's'}`);
                    } else if (c.professor?.name) {
                        details.push(c.professor.name);
                    }

                    return `
                        <div class="notif-row">
                            <div class="notif-row-text">
                                <span class="notif-row-label">${escapeHtml(title)}</span>
                                <span class="notif-row-desc">${escapeHtml(details.filter(Boolean).join(' · '))}</span>
                            </div>
                        </div>`;
                })
                .join('');
        } catch (err) {
            list.innerHTML = '<p class="settings-field-hint">Could not load your classes.</p>';
        }
    }

    /* NOTIFICATION PREFERENCES */
    function renderPreferences() {
        const prefs = settings.notification_preferences || {};
        document.querySelectorAll('input[data-pref]').forEach((toggle) => {
            const key = toggle.dataset.pref;
            // Hide any toggle the server doesn't know for this role.
            const row = toggle.closest('.notif-row');
            if (!(key in prefs)) {
                if (row) row.hidden = true;
                return;
            }
            toggle.checked = Boolean(prefs[key]);
        });
    }

    (function initNotificationPrefs() {
        const saveBtn = document.getElementById('notifSaveBtn');
        const saveStatus = document.getElementById('notifSaveStatus');
        if (!saveBtn) return;

        saveBtn.addEventListener('click', async () => {
            const known = settings?.notification_preferences || {};
            const payload = {};
            document.querySelectorAll('input[data-pref]').forEach((toggle) => {
                if (toggle.dataset.pref in known) payload[toggle.dataset.pref] = toggle.checked;
            });

            setButtonSaving(saveBtn, true);
            try {
                settings = await api('/settings/notifications', { method: 'PUT', json: payload });
                renderPreferences();
                syncNavbar({ alertSound: Boolean(settings.notification_preferences.sound) });
                flashSaveStatus(saveStatus, 'Preferences saved.');
                showToast('Notification preferences saved.', 'success');
            } catch (err) {
                showToast(err.message, 'error');
            } finally {
                setButtonSaving(saveBtn, false);
            }
        });
    })();

    /* CHANGE PASSWORD */
    (function initPasswordForm() {
        const form = document.getElementById('passwordForm');
        const saveStatus = document.getElementById('passwordSaveStatus');

        const currentPw = document.getElementById('currentPassword');
        const newPw = document.getElementById('newPassword');
        const confirmPw = document.getElementById('confirmPassword');

        const strengthBar = document.getElementById('passwordStrengthBar');
        const strengthFill = strengthBar?.querySelector('.password-strength-fill');
        const strengthLabel = document.getElementById('passwordStrengthLabel');
        const matchHint = document.getElementById('passwordMatchHint');

        if (!form) return;

        document.querySelectorAll('.password-toggle-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const input = document.getElementById(btn.getAttribute('data-target'));
                if (!input) return;

                const icon = btn.querySelector('i');
                const isHidden = input.type === 'password';
                input.type = isHidden ? 'text' : 'password';
                icon?.classList.toggle('fa-eye', !isHidden);
                icon?.classList.toggle('fa-eye-slash', isHidden);
                btn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
            });
        });

        function scorePassword(pw) {
            if (!pw) return 0;
            let score = 0;
            if (pw.length >= 8) score++;
            if (pw.length >= 12) score++;
            if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) score++;
            if (/\d/.test(pw)) score++;
            if (/[^A-Za-z0-9]/.test(pw)) score++;
            return Math.min(score, 5);
        }

        function updateStrengthUI() {
            if (!strengthFill || !strengthLabel) return;
            const pw = newPw.value;
            const levels = [
                { pct: 0, label: '', color: '#e2e8f0' },
                { pct: 20, label: 'Very weak', color: '#ef4444' },
                { pct: 40, label: 'Weak', color: '#f97316' },
                { pct: 60, label: 'Fair', color: '#eab308' },
                { pct: 80, label: 'Strong', color: '#22c55e' },
                { pct: 100, label: 'Very strong', color: '#16a34a' },
            ];
            const level = levels[scorePassword(pw)];
            strengthFill.style.width = `${level.pct}%`;
            strengthFill.style.backgroundColor = level.color;
            strengthLabel.textContent = pw ? level.label : '';
            strengthLabel.style.color = pw ? level.color : '#94a3b8';
        }

        function updateMatchUI() {
            if (!matchHint) return;
            matchHint.classList.remove('match', 'no-match');
            if (!confirmPw.value) {
                matchHint.textContent = '';
                return;
            }
            const matches = newPw.value === confirmPw.value;
            matchHint.textContent = matches ? 'Passwords match.' : 'Passwords do not match.';
            matchHint.classList.add(matches ? 'match' : 'no-match');
        }

        function resetForm() {
            form.reset();
            if (strengthFill) strengthFill.style.width = '0%';
            if (strengthLabel) strengthLabel.textContent = '';
            if (matchHint) {
                matchHint.textContent = '';
                matchHint.classList.remove('match', 'no-match');
            }
            document.querySelectorAll('.password-input-wrap input').forEach(input => { input.type = 'password'; });
            document.querySelectorAll('.password-toggle-btn i').forEach(icon => {
                icon.classList.add('fa-eye');
                icon.classList.remove('fa-eye-slash');
            });
        }

        newPw?.addEventListener('input', updateStrengthUI);
        newPw?.addEventListener('input', updateMatchUI);
        confirmPw?.addEventListener('input', updateMatchUI);

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            if (newPw.value.length < 8) {
                showToast('Password must be at least 8 characters.', 'error');
                newPw.focus();
                return;
            }
            if (newPw.value !== confirmPw.value) {
                showToast('New password and confirmation do not match.', 'error');
                confirmPw.focus();
                return;
            }

            const submitBtn = form.querySelector('.settings-save-btn');
            setButtonSaving(submitBtn, true);

            try {
                await api('/settings/password', {
                    method: 'PUT',
                    json: {
                        current_password: currentPw.value,
                        password: newPw.value,
                        password_confirmation: confirmPw.value,
                    },
                });
                resetForm();
                flashSaveStatus(saveStatus, 'Password updated.');
                showToast('Your password has been changed.', 'success');
            } catch (err) {
                showToast(err.status === 429 ? 'Too many attempts. Please wait a minute and try again.' : err.message, 'error');
                if (err.status === 422) currentPw.focus();
            } finally {
                setButtonSaving(submitBtn, false);
            }
        });
    })();

    /* CONTENT WIDTH — inside the shell there's no sidebar in this document */
    (function initContentWidth() {
        const settingsWrap = document.querySelector('.settings-wrap');
        const sidebar = document.getElementById('sidebar');
        if (settingsWrap && (!sidebar || sidebar.classList.contains('collapsed'))) {
            settingsWrap.style.maxWidth = '100%';
        }
    })();

    /* HELPERS */
    function setButtonSaving(btn, isSaving) {
        if (!btn) return;
        if (isSaving) {
            btn.dataset.originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
        } else {
            btn.disabled = false;
            if (btn.dataset.originalHtml) btn.innerHTML = btn.dataset.originalHtml;
        }
    }

    function flashSaveStatus(el, message) {
        if (!el) return;
        el.textContent = message;
        el.classList.add('show');
        clearTimeout(el._hideTimer);
        el._hideTimer = setTimeout(() => el.classList.remove('show'), 2500);
    }

    /* Toasts render in the shell (this page's own #toast is hidden inside the iframe). */
    function showToast(message, type = 'info') {
        try {
            if (window.parent && window.parent !== window && typeof window.parent.showToast === 'function') {
                window.parent.showToast(message, type);
                return;
            }
        } catch (e) {
            /* Fall through to the local toast. */
        }

        const toast = document.getElementById('toast');
        const toastMessage = document.getElementById('toastMessage');
        if (!toast || !toastMessage) return;
        toastMessage.textContent = message;
        toast.classList.add('show');
        clearTimeout(toast._hideTimer);
        toast._hideTimer = setTimeout(() => toast.classList.remove('show'), 3000);
    }

    loadSettings();
});
