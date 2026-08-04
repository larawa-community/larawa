import { Passkeys } from '@laravel/passkeys';

const refreshTarget = document.querySelector('[data-auto-refresh-ms]');

document.querySelectorAll('[data-auto-submit]').forEach((form) => {
    const fallback = form.querySelector('[data-auto-submit-fallback]');

    fallback?.classList.add('hidden');
    form.querySelectorAll('select, input[type="checkbox"], input[type="radio"]').forEach((field) => {
        field.addEventListener('change', () => form.requestSubmit());
    });
});

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function showPasskeyStatus(target, message, type = 'error') {
    if (!target) return;

    target.textContent = message;
    target.classList.remove('hidden', 'border-red-200', 'bg-red-50', 'text-red-700', 'border-emerald-200', 'bg-emerald-50', 'text-emerald-800');
    target.classList.add(
        type === 'success' ? 'border-emerald-200' : 'border-red-200',
        type === 'success' ? 'bg-emerald-50' : 'bg-red-50',
        type === 'success' ? 'text-emerald-800' : 'text-red-700',
    );
}

async function confirmPasskeyAction(confirmUrl, currentPassword, fallbackMessage = 'Unable to confirm your password.') {
    const response = await fetch(confirmUrl, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify({ current_password: currentPassword }),
    });

    if (response.ok) return;

    let message = fallbackMessage;

    try {
        const payload = await response.json();
        message = payload.message || Object.values(payload.errors || {})[0]?.[0] || message;
    } catch (error) {
        // Keep the default message if the response is not JSON.
    }

    throw new Error(message);
}

const passkeyLogin = document.querySelector('[data-passkey-login]');

if (passkeyLogin) {
    const status = document.querySelector('[data-passkey-login-status]');

    if (!Passkeys.isSupported()) {
        passkeyLogin.disabled = true;
        showPasskeyStatus(status, 'Passkeys are not supported in this browser.');
    } else {
        Passkeys.autofill().then((response) => {
            if (response?.redirect) window.location.href = response.redirect;
        }).catch(() => {
            // Explicit button login remains available if autofill fails.
        });

        passkeyLogin.addEventListener('click', async () => {
            passkeyLogin.disabled = true;

            try {
                const response = await Passkeys.verify();

                if (response.redirect) {
                    window.location.href = response.redirect;
                    return;
                }

                window.location.href = '/dashboard';
            } catch (error) {
                showPasskeyStatus(status, error.message || 'Unable to sign in with passkey.');
            } finally {
                passkeyLogin.disabled = false;
            }
        });
    }
}

const passkeyManager = document.querySelector('[data-passkey-manager]');

if (passkeyManager) {
    const status = passkeyManager.querySelector('[data-passkey-status]');
    const registerForm = passkeyManager.querySelector('[data-passkey-register-form]');
    const passkeyText = (key, fallback) => passkeyManager.dataset[key] || fallback;

    if (!Passkeys.isSupported()) {
        showPasskeyStatus(status, passkeyText('unsupported', 'Passkeys are not supported in this browser.'));
    }

    registerForm?.addEventListener('submit', async (event) => {
        event.preventDefault();

        const form = new FormData(registerForm);
        const button = registerForm.querySelector('button');
        button.disabled = true;

        try {
            await confirmPasskeyAction(
                passkeyManager.dataset.confirmUrl,
                form.get('current_password') || '',
                passkeyText('unableConfirm', 'Unable to confirm your password.'),
            );
            await Passkeys.register({ name: form.get('name') || passkeyText('defaultName', 'Passkey') });
            showPasskeyStatus(status, passkeyText('registered', 'Passkey registered.'), 'success');
            window.location.reload();
        } catch (error) {
            showPasskeyStatus(status, error.message || passkeyText('unableRegister', 'Unable to register passkey.'));
        } finally {
            button.disabled = false;
        }
    });

    passkeyManager.querySelectorAll('[data-passkey-delete-form]').forEach((formElement) => {
        formElement.addEventListener('submit', async (event) => {
            event.preventDefault();

            const form = new FormData(formElement);
            const button = formElement.querySelector('button');
            button.disabled = true;

            try {
                await confirmPasskeyAction(
                    passkeyManager.dataset.confirmUrl,
                    form.get('current_password') || '',
                    passkeyText('unableConfirm', 'Unable to confirm your password.'),
                );
                const response = await fetch(formElement.dataset.deleteUrl, {
                    method: 'DELETE',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                    },
                    credentials: 'same-origin',
                });

                if (!response.ok) throw new Error(passkeyText('unableDelete', 'Unable to delete passkey.'));

                showPasskeyStatus(status, passkeyText('deleted', 'Passkey deleted.'), 'success');
                window.location.reload();
            } catch (error) {
                showPasskeyStatus(status, error.message || passkeyText('unableDelete', 'Unable to delete passkey.'));
            } finally {
                button.disabled = false;
            }
        });
    });
}

if (refreshTarget) {
    const delay = Number.parseInt(refreshTarget.dataset.autoRefreshMs || '', 10);

    if (Number.isFinite(delay) && delay > 0) {
        window.setTimeout(() => window.location.reload(), delay);
    }
}

const modalTriggers = [...document.querySelectorAll('[data-modal-open]')];

function closeModal(modal) {
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

function openModal(modal) {
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    const firstField = modal.querySelector('input, select, textarea, button');
    firstField?.focus();
}

modalTriggers.forEach((trigger) => {
    trigger.addEventListener('click', () => {
        const modal = document.querySelector(`[data-modal="${trigger.dataset.modalOpen}"]`);
        if (modal) openModal(modal);
    });
});

document.querySelectorAll('[data-modal]').forEach((modal) => {
    modal.querySelectorAll('[data-modal-close]').forEach((button) => {
        button.addEventListener('click', () => closeModal(modal));
    });

    modal.addEventListener('click', (event) => {
        if (event.target === modal) closeModal(modal);
    });
});

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;

    document.querySelectorAll('[data-modal]:not(.hidden)').forEach(closeModal);
});

document.querySelectorAll('[data-test-message-form]').forEach((form) => {
    const type = form.querySelector('[data-test-message-type]');
    const textFields = form.querySelector('[data-test-message-fields="text"]');
    const mediaFields = form.querySelector('[data-test-message-fields="media"]');

    function syncTestMessageFields() {
        const isText = type?.value === 'text';
        textFields?.classList.toggle('hidden', !isText);
        mediaFields?.classList.toggle('hidden', isText);
    }

    type?.addEventListener('change', syncTestMessageFields);
    syncTestMessageFields();
});

document.querySelectorAll('[data-template-editor]').forEach((editor) => {
    const form = editor.querySelector('[data-template-editor-form]');
    const header = editor.querySelector('[data-template-preview-header]');
    const body = editor.querySelector('[data-template-preview-body]');
    const footer = editor.querySelector('[data-template-preview-footer]');
    const media = editor.querySelector('[data-template-preview-media]');
    const mediaImage = editor.querySelector('[data-template-preview-image]');
    const mediaLabel = editor.querySelector('[data-template-preview-media-label]');
    const buttons = editor.querySelector('[data-template-preview-buttons]');
    let previewObjectUrl = null;

    if (!form) return;

    const value = (name) => form.elements.namedItem(name)?.value?.trim() || '';
    const lines = (name) => value(name).split(/\r?\n/).map((line) => line.trim()).filter(Boolean);

    function examples() {
        if (value('parameter_format') === 'NAMED') {
            return Object.fromEntries(lines('body_named_examples').map((line) => {
                const separator = line.indexOf('=');
                return separator > 0 ? [line.slice(0, separator).trim(), line.slice(separator + 1).trim()] : [line, line];
            }));
        }

        return lines('body_example_values');
    }

    function substituteVariables(text, suppliedExamples) {
        return text.replace(/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*|\d+)\s*\}\}/g, (match, variable) => {
            const replacement = Array.isArray(suppliedExamples)
                ? suppliedExamples[Number.parseInt(variable, 10) - 1]
                : suppliedExamples[variable];

            return replacement || match;
        });
    }

    function renderTemplatePreview() {
        const headerType = value('header_type') || 'NONE';
        const headerText = substituteVariables(value('header_text'), [value('header_example_text')]);
        const bodyText = substituteVariables(value('body_text'), examples());
        const footerText = value('footer_text');

        header.textContent = headerText;
        header.classList.toggle('hidden', headerType !== 'TEXT' || headerText === '');
        body.textContent = bodyText || 'Your template body will appear here.';
        footer.textContent = footerText;
        footer.classList.toggle('hidden', footerText === '');

        const sample = form.elements.namedItem('header_sample_media')?.files?.[0];
        const isMedia = ['IMAGE', 'VIDEO', 'DOCUMENT'].includes(headerType);
        media.classList.toggle('hidden', !isMedia);
        mediaImage.classList.add('hidden');
        mediaLabel.classList.remove('hidden');
        mediaLabel.textContent = `${headerType.charAt(0)}${headerType.slice(1).toLowerCase()} header preview`;

        if (previewObjectUrl) {
            URL.revokeObjectURL(previewObjectUrl);
            previewObjectUrl = null;
        }

        if (isMedia && headerType === 'IMAGE' && sample?.type.startsWith('image/')) {
            previewObjectUrl = URL.createObjectURL(sample);
            mediaImage.src = previewObjectUrl;
            mediaImage.classList.remove('hidden');
            mediaLabel.classList.add('hidden');
        }

        buttons.replaceChildren();
        for (let index = 0; index < 3; index += 1) {
            const type = value(`buttons[${index}][type]`);
            const label = value(`buttons[${index}][text]`);
            if (!type || !label) continue;

            const previewButton = document.createElement('div');
            previewButton.className = 'mt-3 border-t border-slate-100 pt-2 text-center text-sm font-semibold text-sky-600';
            previewButton.textContent = label;
            buttons.append(previewButton);
        }
    }

    form.addEventListener('input', renderTemplatePreview);
    form.addEventListener('change', renderTemplatePreview);
    renderTemplatePreview();
});

const relativeTimeTargets = [...document.querySelectorAll('[data-relative-time]')];

function relativeTimeLabel(timestamp) {
    if (!timestamp) return 'Never';

    const date = new Date(timestamp);

    if (Number.isNaN(date.getTime())) return 'Never';

    const seconds = Math.max(0, Math.floor((Date.now() - date.getTime()) / 1000));

    if (seconds < 60) return `${seconds} second${seconds === 1 ? '' : 's'} ago`;

    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes} minute${minutes === 1 ? '' : 's'} ago`;

    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours} hour${hours === 1 ? '' : 's'} ago`;

    const days = Math.floor(hours / 24);
    return `${days} day${days === 1 ? '' : 's'} ago`;
}

function updateRelativeTimes() {
    relativeTimeTargets.forEach((target) => {
        target.textContent = relativeTimeLabel(target.dataset.timestamp);
    });
}

if (relativeTimeTargets.length > 0) {
    updateRelativeTimes();
    window.setInterval(updateRelativeTimes, 1000);
}

const settingsSections = [...document.querySelectorAll('[data-settings-section]')];

function openSettingsSectionFromHash() {
    if (settingsSections.length === 0 || !window.location.hash) return;

    const section = document.querySelector(window.location.hash);

    if (section?.matches('[data-settings-section]')) {
        section.open = true;
    }
}

if (settingsSections.length > 0) {
    document.querySelectorAll('[data-settings-section-link]').forEach((link) => {
        link.addEventListener('click', () => {
            const section = document.querySelector(link.hash);

            if (section?.matches('[data-settings-section]')) {
                section.open = true;
            }
        });
    });

    openSettingsSectionFromHash();
    window.addEventListener('hashchange', openSettingsSectionFromHash);
}

const liveSession = document.querySelector('[data-session-live-url]');

function renderMessage(message) {
    const wrapper = document.createElement('div');
    wrapper.className = 'px-5 py-4';
    wrapper.dataset.sessionMessageId = message.id;

    const header = document.createElement('div');
    header.className = 'flex items-center justify-between gap-3';

    const title = document.createElement('div');
    title.className = 'font-medium';
    title.textContent = message.title || message.type || 'message';

    const status = document.createElement('span');
    status.className = 'rounded-full bg-slate-100 px-2 py-1 text-xs';
    status.textContent = message.status || 'received';

    header.append(title, status);

    const meta = document.createElement('div');
    meta.className = 'mt-1 text-sm text-slate-500';
    meta.textContent = `${message.direction || '-'} | ${message.from || ''} -> ${message.to || ''}`;

    wrapper.append(header, meta);

    if (message.media_url) {
        const link = document.createElement('a');
        link.href = message.media_url;
        link.className = 'mt-2 inline-flex text-sm font-semibold text-[#128c42]';
        link.textContent = 'Download media';
        wrapper.append(link);
    }

    return wrapper;
}

function updateMessages(messages) {
    const container = document.querySelector('[data-session-messages]');
    if (!container) return;

    container.replaceChildren();

    if (!messages || messages.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'px-5 py-8 text-sm text-slate-500';
        empty.textContent = 'No messages for this session.';
        container.append(empty);
        return;
    }

    messages.forEach((message) => container.append(renderMessage(message)));
}

if (liveSession) {
    const pollLiveSession = async () => {
        try {
            const response = await fetch(liveSession.dataset.sessionLiveUrl, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) return;

            const snapshot = await response.json();
            const session = snapshot.session || {};

            if (session.status && liveSession.dataset.sessionCurrentStatus !== session.status) {
                window.location.reload();
                return;
            }

            const phone = document.querySelector('[data-session-phone]');
            const worker = document.querySelector('[data-session-worker]');
            const lastSeen = document.querySelector('[data-session-last-seen]');
            const lastCheck = document.querySelector('[data-session-last-check]');

            if (phone) phone.textContent = session.phone_number || 'Not connected';
            if (worker) worker.textContent = session.worker_status || 'Not running';
            if (lastSeen) lastSeen.dataset.timestamp = session.last_seen_at || '';
            if (lastCheck) lastCheck.dataset.timestamp = session.worker_synced_at || '';

            updateRelativeTimes();
            updateMessages(snapshot.messages || []);
        } catch (error) {
            // Keep the current dashboard snapshot if a poll fails.
        }
    };

    window.setInterval(pollLiveSession, 8000);
}

const setupWizard = document.querySelector('[data-setup-wizard]');

if (setupWizard) {
    const panels = [...setupWizard.querySelectorAll('[data-wizard-step-panel]')];
    const stepButtons = [...setupWizard.querySelectorAll('[data-wizard-step-button]')];
    const backButton = setupWizard.querySelector('[data-wizard-back]');
    const nextButton = setupWizard.querySelector('[data-wizard-next]');
    const summary = setupWizard.querySelector('[data-setup-summary]');
    const form = setupWizard.querySelector('[data-setup-form]');
    const dbConnection = setupWizard.querySelector('[data-db-connection]');
    const redisToggle = setupWizard.querySelector('[data-redis-toggle]');
    const storageDisk = setupWizard.querySelector('[data-storage-disk]');
    const navigation = setupWizard.querySelector('[data-wizard-navigation]');
    const installSubmit = setupWizard.querySelector('[data-install-submit]');
    const installReady = setupWizard.querySelector('[data-install-ready]');
    const installProgress = setupWizard.querySelector('[data-install-progress]');
    const installComplete = setupWizard.querySelector('[data-install-complete]');
    const installLogin = setupWizard.querySelector('[data-install-login]');
    const progressId = setupWizard.querySelector('[data-setup-progress-id]');
    const progressTitle = setupWizard.querySelector('[data-install-progress-title]');
    const progressMessage = setupWizard.querySelector('[data-install-progress-message]');
    const progressPercent = setupWizard.querySelector('[data-install-progress-percent]');
    const progressBar = setupWizard.querySelector('[data-install-progress-bar]');
    const previewMode = setupWizard.dataset.setupPreview === 'true';
    const completedProgressSteps = new Set();
    let progressTimer = null;
    let currentStep = 0;
    let currentProgressPercent = 0;
    let installing = false;

    const fieldValue = (name) => {
        const field = form?.elements.namedItem(name);
        if (!field) return '';
        if (field instanceof RadioNodeList) return field.value || '';
        if (field.type === 'checkbox') return field.checked ? 'enabled' : 'disabled';
        return field.value || '';
    };

    const mask = (value) => (value ? 'configured' : 'not set');

    const summaryItems = () => [
        ['Public URL', fieldValue('app_url')],
        ['Timezone', fieldValue('app_timezone')],
        ['Cloudflare Flexible SSL', fieldValue('cloudflare_flexible_ssl')],
        ['Worker URL', fieldValue('worker_url')],
        ['Worker callback', fieldValue('worker_callback_url')],
        ['Worker token', mask(fieldValue('worker_token'))],
        ['API rate limit', fieldValue('api_rate_limit_per_minute')],
        ['Webhook timeout', `${fieldValue('webhook_timeout') || '-'} seconds`],
        ['Webhook retries', fieldValue('webhook_retry_attempts')],
        ['Database', fieldValue('db_connection')],
        ['DB host', fieldValue('db_connection') === 'sqlite' ? fieldValue('sqlite_database') : `${fieldValue('db_host')}:${fieldValue('db_port')}`],
        ['DB name', fieldValue('db_connection') === 'sqlite' ? 'SQLite file' : fieldValue('db_database')],
        ['Redis', fieldValue('use_redis')],
        ['Storage', fieldValue('filesystem_disk')],
        ['S3 bucket', fieldValue('filesystem_disk') === 's3' ? fieldValue('aws_bucket') : 'local disk'],
        ['Workspace', fieldValue('workspace_name')],
        ['Site admin', fieldValue('email')],
    ];

    function renderSummary() {
        if (!summary) return;
        summary.replaceChildren();

        summaryItems().forEach(([label, value]) => {
            const item = document.createElement('div');
            item.className = 'rounded-md border border-slate-200 bg-slate-50 px-4 py-3';

            const title = document.createElement('div');
            title.className = 'text-xs font-semibold uppercase text-slate-500';
            title.textContent = label;

            const body = document.createElement('div');
            body.className = 'mt-1 break-words text-sm font-medium text-slate-900';
            body.textContent = value || '-';

            item.append(title, body);
            summary.append(item);
        });
    }

    function updateDatabaseFields() {
        const selected = dbConnection?.value || 'sqlite';

        setupWizard.querySelectorAll('[data-db-fields]').forEach((block) => {
            const visible = block.dataset.dbFields.split(/\s+/).includes(selected);
            toggleFields(block, visible);
        });
    }

    function updateDatabasePort() {
        const port = form?.elements.namedItem('db_port');
        if (!port || port.value) return;
        port.value = dbConnection?.value === 'pgsql' ? '5432' : '3306';
    }

    function toggleFields(block, visible) {
        block.classList.toggle('hidden', !visible);
        block.querySelectorAll('input, select, textarea').forEach((field) => {
            field.disabled = previewMode || !visible;
        });
    }

    function updateRedisFields() {
        const enabled = Boolean(redisToggle?.checked);
        setupWizard.querySelectorAll('[data-redis-fields]').forEach((block) => toggleFields(block, enabled));
        setupWizard.querySelectorAll('[data-redis-disabled]').forEach((block) => {
            block.classList.toggle('hidden', enabled);
        });
    }

    function updateStorageFields() {
        const selected = storageDisk?.value || 'local';
        setupWizard.querySelectorAll('[data-storage-fields]').forEach((block) => {
            const visible = block.dataset.storageFields.split(/\s+/).includes(selected);
            toggleFields(block, visible);
        });
    }

    function showStep(step) {
        currentStep = Math.max(0, Math.min(step, panels.length - 1));

        panels.forEach((panel, index) => {
            panel.classList.toggle('hidden', index !== currentStep);
        });

        stepButtons.forEach((button, index) => {
            button.classList.toggle('border-[#25d366]', index === currentStep);
            button.classList.toggle('bg-[#25d366]/10', index === currentStep);
            button.classList.toggle('text-[#128c42]', index === currentStep);
            button.classList.toggle('text-slate-500', index !== currentStep);
        });

        if (backButton) backButton.disabled = currentStep === 0;
        if (nextButton) nextButton.classList.toggle('hidden', currentStep === panels.length - 1);

        if (currentStep >= 5) renderSummary();
    }

    function validationMessage(field) {
        const value = field.value || '';
        field.setCustomValidity('');

        if (field.name === 'worker_token' && value.length < 32) {
            return 'Worker internal token must be at least 32 characters.';
        }

        if (field.name === 'password' && value.length < 8) {
            return 'Password must be at least 8 characters.';
        }

        if (field.name === 'password_confirmation') {
            const password = form?.elements.namedItem('password')?.value || '';
            if (value !== password) return 'Password confirmation must match the password.';
        }

        if (!field.checkValidity()) {
            return field.validationMessage;
        }

        return '';
    }

    function firstInvalidField(fields) {
        return fields.find((field) => {
            if (field.disabled) return false;
            const message = validationMessage(field);
            if (!message) return false;

            field.setCustomValidity(message);
            return true;
        });
    }

    function validateCurrentStep() {
        const fields = [...panels[currentStep].querySelectorAll('input, select, textarea')];
        const invalid = firstInvalidField(fields);
        if (!invalid) return true;

        invalid.reportValidity();
        return false;
    }

    function validateAllSteps() {
        for (let index = 0; index < panels.length; index += 1) {
            const fields = [...panels[index].querySelectorAll('input, select, textarea')];
            const invalid = firstInvalidField(fields);
            if (invalid) {
                showStep(index);
                invalid.reportValidity();
                return false;
            }
        }

        return true;
    }

    function progressUrl() {
        const template = form?.dataset.progressUrl || '';
        const id = progressId?.value || '';
        if (!template || !id) return '';

        return template.replace('__ID__', encodeURIComponent(id));
    }

    function updateProgressState(progress) {
        const percent = Math.max(0, Math.min(100, Number(progress.percent) || 0));
        currentProgressPercent = percent;

        if (progressTitle) {
            progressTitle.textContent = progress.failed ? 'Installation failed' : progress.complete ? 'Installation complete' : 'Installing LaraWA...';
        }

        if (progressMessage) progressMessage.textContent = progress.message || 'Working...';
        if (progressPercent) progressPercent.textContent = `${percent}%`;
        if (progressBar) progressBar.style.width = `${percent}%`;

        if (progress.step) {
            completedProgressSteps.add(progress.step);
        }

        setupWizard.querySelectorAll('[data-install-step]').forEach((item) => {
            const dot = item.querySelector('span');
            const active = item.dataset.installStep === progress.step;
            const complete = completedProgressSteps.has(item.dataset.installStep);
            const dotColor = active && progress.failed ? 'bg-red-500' : complete ? 'bg-[#25d366]' : 'bg-sky-300';

            item.classList.toggle('font-semibold', active);
            item.classList.toggle('text-sky-900', active || complete);
            item.classList.toggle('text-sky-700', !active && !complete);
            dot?.classList.remove('bg-[#25d366]', 'bg-red-500', 'bg-sky-300');
            dot?.classList.add(dotColor);
        });
    }

    function stopProgressPolling() {
        if (!progressTimer) return;
        window.clearInterval(progressTimer);
        progressTimer = null;
    }

    async function fetchProgressState() {
        const url = progressUrl();
        if (!url) return null;

        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) return null;

        return response.json();
    }

    function startProgressPolling() {
        const url = progressUrl();
        if (!url) return;

        stopProgressPolling();
        updateProgressState({
            step: 'waiting',
            message: 'Waiting for installer to start.',
            percent: 0,
        });

        const poll = async () => {
            try {
                const progress = await fetchProgressState();
                if (!progress) return;

                updateProgressState(progress);

                if (progress.complete || progress.failed) {
                    stopProgressPolling();
                }
            } catch {
                // The final redirect can interrupt polling; the form submit handler owns user-visible errors.
            }
        };

        poll();
        progressTimer = window.setInterval(poll, 700);
    }

    function showInstallationComplete(redirect) {
        stopProgressPolling();
        updateProgressState({
            step: 'complete',
            message: 'LaraWA setup is complete.',
            percent: 100,
            complete: true,
        });

        if (redirect && installLogin) installLogin.href = redirect;
        installComplete?.classList.remove('hidden');
        installSubmit.classList.add('hidden');
        installSubmit.disabled = true;
    }

    backButton?.addEventListener('click', () => showStep(currentStep - 1));
    nextButton?.addEventListener('click', () => {
        if (validateCurrentStep()) showStep(currentStep + 1);
    });
    stepButtons.forEach((button, index) => {
        button.addEventListener('click', () => {
            if (index <= currentStep || validateCurrentStep()) showStep(index);
        });
    });

    setupWizard.querySelector('[data-generate-worker-token]')?.addEventListener('click', () => {
        const target = setupWizard.querySelector('[data-worker-token]');
        const bytes = new Uint8Array(48);
        window.crypto.getRandomValues(bytes);
        target.value = [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');
        target.type = 'text';
        target.focus();
    });

    setupWizard.addEventListener('input', (event) => {
        event.target?.setCustomValidity?.('');
        if (currentStep >= 5) renderSummary();
    });

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (previewMode) {
            return;
        }

        if (installing) {
            return;
        }

        if (!validateAllSteps()) {
            return;
        }

        installing = true;
        renderSummary();
        installReady?.classList.add('hidden');
        installProgress?.classList.remove('hidden');
        navigation?.classList.add('hidden');
        installSubmit.disabled = true;
        installSubmit.textContent = 'Installing...';
        form.querySelectorAll('button').forEach((button) => {
            if (button !== installSubmit) button.disabled = true;
        });

        startProgressPolling();

        try {
            const response = await fetch(form.action, {
                method: (form.getAttribute('method') || 'POST').toUpperCase(),
                body: new FormData(form),
                credentials: 'same-origin',
                redirect: 'manual',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (response.type === 'opaqueredirect' || response.status === 0) {
                throw new Error('Installer returned a redirect instead of JSON. Please refresh after rebuilding so the setup page uses the latest installer assets.');
            }

            const contentType = response.headers.get('content-type') || '';
            if (response.ok) {
                if (!contentType.includes('application/json')) {
                    throw new Error('Installer returned a non-JSON success response, so completion could not be verified.');
                }

                const payload = await response.json();
                if (payload.installed !== true) {
                    throw new Error(payload.message || 'Installer response did not confirm installation.');
                }

                const progress = await fetchProgressState();
                if (!progress?.complete || progress.failed) {
                    throw new Error(progress?.message || 'Installer progress did not confirm completion.');
                }

                updateProgressState(progress);
                showInstallationComplete(payload.redirect || '/login');
                return;
            }

            let message = `Installation failed with HTTP ${response.status}.`;
            if (contentType.includes('application/json')) {
                const payload = await response.json();
                message = payload.message || Object.values(payload.errors || {}).flat()[0] || message;
            }

            throw new Error(message);
        } catch (error) {
            stopProgressPolling();
            installing = false;
            updateProgressState({
                step: 'failed',
                message: error.message || 'Installation failed. Please check the setup values and try again.',
                percent: currentProgressPercent,
                failed: true,
            });
            installSubmit.disabled = false;
            installSubmit.textContent = 'Execute installation';
            navigation?.classList.remove('hidden');
            form.querySelectorAll('button').forEach((button) => {
                button.disabled = false;
            });
        }
    });

    dbConnection?.addEventListener('change', () => {
        updateDatabaseFields();
        updateDatabasePort();
        if (currentStep >= 5) renderSummary();
    });
    redisToggle?.addEventListener('change', () => {
        updateRedisFields();
        if (currentStep >= 5) renderSummary();
    });
    storageDisk?.addEventListener('change', () => {
        updateStorageFields();
        if (currentStep >= 5) renderSummary();
    });

    updateDatabaseFields();
    updateRedisFields();
    updateStorageFields();
    showStep(0);
}
