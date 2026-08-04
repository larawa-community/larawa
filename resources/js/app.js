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

const collapsibleSidebar = document.querySelector('[data-collapsible-sidebar]');
const cloudSidebarStorageKey = 'larawa:cloud-sidebar-expanded';

if (collapsibleSidebar) {
    const panel = collapsibleSidebar.querySelector('[data-sidebar-panel]');
    const toggle = collapsibleSidebar.querySelector('[data-sidebar-toggle]');
    const toggleIcon = collapsibleSidebar.querySelector('[data-sidebar-toggle-icon]');
    const toggleLabel = collapsibleSidebar.querySelector('[data-sidebar-toggle-label]');
    const labels = [...collapsibleSidebar.querySelectorAll('[data-sidebar-label]')];
    const items = [...collapsibleSidebar.querySelectorAll('[data-sidebar-item]')];

    function storedSidebarState() {
        try {
            return window.sessionStorage.getItem(cloudSidebarStorageKey) === 'true';
        } catch {
            return false;
        }
    }

    function setSidebarExpanded(expanded) {
        collapsibleSidebar.classList.toggle('w-20', !expanded);
        collapsibleSidebar.classList.toggle('w-64', expanded);
        panel?.classList.toggle('w-20', !expanded);
        panel?.classList.toggle('w-64', expanded);
        labels.forEach((label) => label.classList.toggle('hidden', !expanded));
        items.forEach((item) => {
            item.classList.toggle('justify-center', !expanded);
            item.classList.toggle('px-2', !expanded);
            item.classList.toggle('justify-start', expanded);
            item.classList.toggle('px-3', expanded);
        });
        toggle?.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        if (toggle) toggle.title = expanded ? 'Collapse navigation' : 'Keep navigation open';
        if (toggleLabel) toggleLabel.textContent = expanded ? 'Collapse navigation' : 'Keep navigation open';
        toggleIcon?.classList.toggle('rotate-180', expanded);

        try {
            window.sessionStorage.setItem(cloudSidebarStorageKey, expanded ? 'true' : 'false');
        } catch {
            // The navigation still works when browser storage is unavailable.
        }
    }

    setSidebarExpanded(storedSidebarState());
    toggle?.addEventListener('click', () => {
        setSidebarExpanded(toggle.getAttribute('aria-expanded') !== 'true');
    });
} else {
    try {
        window.sessionStorage.removeItem(cloudSidebarStorageKey);
    } catch {
        // Ignore unavailable browser storage.
    }
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

const cloudInbox = document.querySelector('[data-cloud-inbox]');

if (cloudInbox) {
    const baseDelay = 5000;
    const maximumDelay = 30000;
    const selectedId = cloudInbox.dataset.cloudInboxSelectedId || '';
    const mobileShowingDetail = cloudInbox.dataset.cloudInboxMobileDetail === 'true';
    const conversationList = cloudInbox.querySelector('[data-cloud-inbox-conversations]');
    const messageList = cloudInbox.querySelector('[data-cloud-inbox-messages]');
    const total = cloudInbox.querySelector('[data-cloud-inbox-total]');
    const liveStatus = cloudInbox.querySelector('[data-cloud-inbox-live-status]');
    const liveDot = cloudInbox.querySelector('[data-cloud-inbox-live-dot]');
    const liveLabel = cloudInbox.querySelector('[data-cloud-inbox-live-label]');
    const composer = cloudInbox.querySelector('[data-cloud-inbox-composer]');
    const replyForm = cloudInbox.querySelector('[data-cloud-inbox-reply-form]');
    const replyText = cloudInbox.querySelector('[data-cloud-inbox-reply-text]');
    const mediaForm = cloudInbox.querySelector('[data-cloud-inbox-media-form]');
    const fileInput = cloudInbox.querySelector('[data-cloud-inbox-file-input]');
    const filePreview = cloudInbox.querySelector('[data-cloud-inbox-file-preview]');
    const fileName = cloudInbox.querySelector('[data-cloud-inbox-file-name]');
    const fileMeta = cloudInbox.querySelector('[data-cloud-inbox-file-meta]');
    const fileError = cloudInbox.querySelector('[data-cloud-inbox-file-error]');
    const fileRemove = cloudInbox.querySelector('[data-cloud-inbox-file-remove]');
    const mediaSubmit = cloudInbox.querySelector('[data-cloud-inbox-media-submit]');
    let timer = null;
    let inFlight = false;
    let failures = 0;
    let conversationSignature = null;
    let messageSignature = null;
    let filePreviewUrl = null;

    function setLiveStatus(state) {
        const reconnecting = state === 'reconnecting';
        liveStatus?.classList.toggle('bg-emerald-50', !reconnecting);
        liveStatus?.classList.toggle('text-emerald-700', !reconnecting);
        liveStatus?.classList.toggle('bg-amber-50', reconnecting);
        liveStatus?.classList.toggle('text-amber-800', reconnecting);
        liveDot?.classList.toggle('bg-emerald-500', !reconnecting);
        liveDot?.classList.toggle('bg-amber-500', reconnecting);
        if (liveLabel) liveLabel.textContent = reconnecting ? 'Reconnecting…' : 'Live updates';
    }

    function renderConversation(conversation) {
        const link = document.createElement('a');
        const isSelected = String(conversation.id) === selectedId
            && (mobileShowingDetail || window.matchMedia('(min-width: 1024px)').matches);
        link.href = conversation.show_url;
        link.className = `block border-l-4 px-4 py-4 transition ${isSelected ? 'border-[#128c42] bg-white shadow-sm' : 'border-transparent hover:bg-white'}`;

        const header = document.createElement('div');
        header.className = 'flex items-start justify-between gap-3';

        const customer = document.createElement('div');
        customer.className = 'min-w-0';
        const name = document.createElement('div');
        name.className = 'truncate text-sm font-semibold text-slate-900';
        name.textContent = conversation.customer_name;
        const number = document.createElement('div');
        number.className = 'mt-1 font-mono text-xs text-slate-500';
        number.textContent = conversation.customer_wa_id;
        customer.append(name, number);

        const windowState = document.createElement('span');
        windowState.className = `shrink-0 rounded-full px-2 py-1 text-[10px] font-bold uppercase ${conversation.service_window_open ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600'}`;
        windowState.textContent = conversation.service_window_open ? 'Open' : 'Closed';
        header.append(customer, windowState);

        const meta = document.createElement('div');
        meta.className = 'mt-3 flex items-center justify-between text-xs text-slate-500';
        const count = document.createElement('span');
        count.textContent = `${conversation.messages_count} messages`;
        const latest = document.createElement('span');
        latest.textContent = conversation.latest_message_label;
        meta.append(count, latest);
        link.append(header, meta);

        return link;
    }

    function updateConversations(conversations, pagination) {
        if (total) total.textContent = pagination?.total ?? conversations.length;
        if (!conversationList) return;
        const nextSignature = JSON.stringify([conversations, pagination]);
        if (conversationSignature === nextSignature) return;
        conversationSignature = nextSignature;

        const scrollTop = conversationList.scrollTop;
        conversationList.replaceChildren();
        if (conversations.length > 0) {
            conversations.forEach((conversation) => conversationList.append(renderConversation(conversation)));
            conversationList.scrollTop = scrollTop;
            return;
        }

        const empty = document.createElement('div');
        empty.className = 'px-5 py-16 text-center';
        const icon = document.createElement('div');
        icon.className = 'mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-emerald-50 text-lg text-[#128c42]';
        icon.textContent = '✦';
        const title = document.createElement('h4');
        title.className = 'mt-4 text-sm font-semibold text-slate-900';
        title.textContent = 'No customer enquiries yet';
        const description = document.createElement('p');
        description.className = 'mt-2 text-xs leading-5 text-slate-500';
        description.textContent = 'Signed Meta webhooks will create conversations here when customers message this number.';
        empty.append(icon, title, description);
        conversationList.append(empty);
    }

    function renderConversationMessage(message) {
        const wrapper = document.createElement('div');
        const outgoing = message.direction === 'outgoing';
        wrapper.className = `flex ${outgoing ? 'justify-end' : 'justify-start'}`;

        const article = document.createElement('article');
        article.className = `max-w-[86%] rounded-2xl px-4 py-3 shadow-sm sm:max-w-[72%] ${outgoing ? 'rounded-br-sm bg-[#d9fdd3] text-slate-900' : 'rounded-bl-sm border border-slate-200 bg-white text-slate-900'}`;
        const hasImage = ['image', 'sticker'].includes(message.type) && message.media_url;
        if (hasImage) {
            const media = document.createElement('a');
            media.href = message.download_url || message.media_url;
            media.className = '-mx-2 -mt-1 mb-2 block overflow-hidden rounded-xl bg-slate-100';
            media.title = `Download ${message.filename || 'image'}`;
            const image = document.createElement('img');
            image.src = message.media_url;
            image.alt = message.body || 'Shared image';
            image.className = 'max-h-80 w-full object-cover';
            image.loading = 'lazy';
            image.dataset.cloudInboxMediaImage = '';
            media.dataset.cloudInboxImageLink = '';
            image.addEventListener('error', () => showUnavailableImage(image));
            media.append(image);
            article.append(media);
        } else if (message.type === 'audio' && message.media_url) {
            const audioCard = document.createElement('div');
            audioCard.className = 'min-w-[15rem] rounded-xl border border-black/5 bg-white/70 p-3 sm:min-w-80';

            const label = document.createElement('div');
            label.className = 'mb-2 flex items-center gap-2 text-xs font-semibold text-slate-700';
            label.innerHTML = '<span class="flex h-7 w-7 items-center justify-center rounded-full bg-[#128c42] text-white"><svg viewBox="0 0 24 24" class="h-4 w-4 fill-current" aria-hidden="true"><path d="M12 14a3 3 0 0 0 3-3V5a3 3 0 1 0-6 0v6a3 3 0 0 0 3 3Zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 6 6.92V21H8v2h8v-2h-3v-3.08A7 7 0 0 0 19 11Z"/></svg></span>';
            label.append(document.createTextNode(message.is_voice ? 'Voice message' : 'Audio message'));

            const audio = document.createElement('audio');
            audio.controls = true;
            audio.preload = 'metadata';
            audio.className = 'h-10 w-full';
            audio.dataset.cloudInboxMediaAudio = '';
            audio.addEventListener('error', () => showUnavailableAudio(audio));
            const source = document.createElement('source');
            source.src = message.media_url;
            source.type = message.mime_type || 'audio/ogg';
            audio.append(source);

            const download = document.createElement('a');
            download.href = message.download_url || message.media_url;
            download.className = 'mt-2 inline-flex text-[10px] font-semibold uppercase text-[#128c42]';
            download.textContent = 'Download audio';
            audioCard.append(label, audio, download);
            article.append(audioCard);
        } else if (message.media_url) {
            const media = document.createElement('a');
            media.href = message.download_url || message.media_url;
            media.className = 'flex min-w-0 items-center gap-3 rounded-xl border border-black/5 bg-white/70 p-3 transition hover:bg-white';

            const icon = document.createElement('span');
            icon.className = 'flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-900 text-white';
            icon.innerHTML = '<svg viewBox="0 0 24 24" class="h-5 w-5 fill-none stroke-current" stroke-width="1.8" aria-hidden="true"><path d="M7 3.75h7l4 4v12.5H7z"/><path d="M14 3.75v4h4M9.5 13h5M9.5 16h4"/></svg>';

            const details = document.createElement('span');
            details.className = 'min-w-0 flex-1';
            const attachmentName = document.createElement('span');
            attachmentName.className = 'block truncate text-sm font-semibold';
            attachmentName.textContent = message.filename || 'Attachment';
            const attachmentType = document.createElement('span');
            attachmentType.className = 'mt-0.5 block text-[10px] uppercase text-slate-500';
            attachmentType.textContent = `${message.mime_type || 'Document'} · Download`;
            details.append(attachmentName, attachmentType);
            media.append(icon, details);
            article.append(media);
        }

        const shouldShowBody = message.body && (!message.media_url || message.body !== `${message.type?.charAt(0).toUpperCase()}${message.type?.slice(1)} message`);
        if (shouldShowBody) {
            const body = document.createElement('div');
            body.className = `${message.media_url ? 'mt-2 ' : ''}whitespace-pre-wrap break-words text-sm leading-6`;
            body.textContent = message.body;
            article.append(body);
        }

        const meta = document.createElement('div');
        meta.className = 'mt-2 flex items-center justify-end gap-2 text-[10px] text-slate-500';
        const createdAt = document.createElement('span');
        createdAt.textContent = message.created_at_label || '';
        meta.append(createdAt);
        if (outgoing) {
            const status = document.createElement('span');
            status.className = 'font-semibold uppercase';
            status.textContent = message.status || '';
            meta.append(status);
        }
        article.append(meta);
        wrapper.append(article);

        return wrapper;
    }

    function showUnavailableImage(image) {
        const link = image.closest('[data-cloud-inbox-image-link]');
        if (!link || link.dataset.mediaUnavailable === 'true') return;
        link.dataset.mediaUnavailable = 'true';

        const unavailable = document.createElement('div');
        unavailable.className = 'flex min-h-28 items-center justify-center p-5 text-center';
        const content = document.createElement('div');
        const title = document.createElement('div');
        title.className = 'text-sm font-semibold text-slate-700';
        title.textContent = 'Image unavailable';
        const detail = document.createElement('div');
        detail.className = 'mt-1 text-xs text-slate-500';
        detail.textContent = 'The media could not be loaded.';
        content.append(title, detail);
        unavailable.append(content);
        link.removeAttribute('href');
        link.removeAttribute('title');
        link.replaceChildren(unavailable);
    }

    function showUnavailableAudio(audio) {
        const card = audio.parentElement;
        if (!card || card.dataset.mediaUnavailable === 'true') return;
        card.dataset.mediaUnavailable = 'true';

        const title = document.createElement('div');
        title.className = 'text-sm font-semibold text-slate-700';
        title.textContent = 'Audio unavailable';
        const detail = document.createElement('div');
        detail.className = 'mt-1 text-xs text-slate-500';
        detail.textContent = 'The voice message could not be loaded.';
        card.replaceChildren(title, detail);
    }

    function updateConversationMessages(messages) {
        if (!messageList) return;
        const nextSignature = JSON.stringify(messages.map((message) => [message.id, message.status, message.type, message.body, message.media_url, message.mime_type, message.filename, message.is_voice]));
        if (messageSignature === nextSignature) return;

        const stayAtBottom = messageSignature === null || messageList.scrollHeight - messageList.scrollTop - messageList.clientHeight < 96;
        messageSignature = nextSignature;
        messageList.replaceChildren();

        if (messages.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'py-20 text-center text-sm text-slate-500';
            empty.textContent = 'No messages are stored for this conversation yet.';
            messageList.append(empty);
        } else {
            messages.forEach((message) => messageList.append(renderConversationMessage(message)));
        }

        if (stayAtBottom) {
            window.requestAnimationFrame(() => {
                messageList.scrollTop = messageList.scrollHeight;
            });
        }
    }

    function updateSelectedConversation(conversation) {
        if (!conversation) return;
        const name = cloudInbox.querySelector('[data-cloud-inbox-customer-name]');
        const number = cloudInbox.querySelector('[data-cloud-inbox-customer-number]');
        const serviceWindow = cloudInbox.querySelector('[data-cloud-inbox-window]');
        const windowTitle = cloudInbox.querySelector('[data-cloud-inbox-window-title]');
        const windowDetail = cloudInbox.querySelector('[data-cloud-inbox-window-detail]');
        const replyClosed = cloudInbox.querySelector('[data-cloud-inbox-reply-closed]');
        const isOpen = conversation.service_window_open;

        if (name) name.textContent = conversation.customer_name;
        if (number) number.textContent = conversation.customer_wa_id;
        serviceWindow?.classList.toggle('border-emerald-200', isOpen);
        serviceWindow?.classList.toggle('bg-emerald-50', isOpen);
        serviceWindow?.classList.toggle('border-amber-200', !isOpen);
        serviceWindow?.classList.toggle('bg-amber-50', !isOpen);
        windowTitle?.classList.toggle('text-emerald-700', isOpen);
        windowTitle?.classList.toggle('text-amber-800', !isOpen);
        if (windowTitle) windowTitle.textContent = `24-hour service window ${isOpen ? 'open' : 'closed'}`;
        if (windowDetail) {
            windowDetail.textContent = isOpen
                ? `Free-form replies until ${conversation.service_window_expires_label}`
                : 'Replies are paused until the customer messages this number again.';
        }
        if (replyForm) {
            replyForm.action = conversation.reply_url;
        }
        if (mediaForm) mediaForm.action = conversation.media_reply_url;
        composer?.classList.toggle('hidden', !isOpen);
        composer?.classList.toggle('block', isOpen);
        replyClosed?.classList.toggle('hidden', isOpen);
    }

    function readableFileSize(bytes) {
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    }

    function resetAttachment() {
        if (filePreviewUrl) URL.revokeObjectURL(filePreviewUrl);
        filePreviewUrl = null;
        if (fileInput) fileInput.value = '';
        mediaForm?.classList.add('hidden');
        if (fileError) {
            fileError.textContent = '';
            fileError.classList.add('hidden');
        }
    }

    function showAttachment(file) {
        const maxBytes = Number(fileInput?.dataset.maxBytes || 0);
        const imageMaxBytes = 5 * 1024 * 1024;
        const exceedsLimit = (maxBytes > 0 && file.size > maxBytes) || (file.type.startsWith('image/') && file.size > imageMaxBytes);

        mediaForm?.classList.remove('hidden');
        if (fileName) fileName.textContent = file.name;
        if (fileMeta) fileMeta.textContent = `${file.type.startsWith('image/') ? 'Image' : 'Document'} · ${readableFileSize(file.size)}`;
        if (fileError) {
            fileError.textContent = exceedsLimit
                ? `This ${file.type.startsWith('image/') ? 'image' : 'file'} is too large. ${file.type.startsWith('image/') ? 'Images can be up to 5 MB.' : `Files can be up to ${readableFileSize(maxBytes)}.`}`
                : '';
            fileError.classList.toggle('hidden', !exceedsLimit);
        }
        if (mediaSubmit) mediaSubmit.disabled = exceedsLimit;

        if (!filePreview) return;
        if (filePreviewUrl) URL.revokeObjectURL(filePreviewUrl);
        filePreviewUrl = null;
        filePreview.replaceChildren();
        if (file.type.startsWith('image/')) {
            filePreviewUrl = URL.createObjectURL(file);
            const image = document.createElement('img');
            image.src = filePreviewUrl;
            image.alt = '';
            image.className = 'h-full w-full object-cover';
            filePreview.append(image);
        } else {
            filePreview.innerHTML = '<svg viewBox="0 0 24 24" class="h-6 w-6 fill-none stroke-current" stroke-width="1.8" aria-hidden="true"><path d="M7 3.75h7l4 4v12.5H7z"/><path d="M14 3.75v4h4"/></svg>';
        }
    }

    function schedule(delay) {
        window.clearTimeout(timer);
        timer = window.setTimeout(poll, delay);
    }

    async function poll() {
        if (inFlight) return;
        if (document.visibilityState !== 'visible') {
            schedule(baseDelay);
            return;
        }

        inFlight = true;
        try {
            const response = await fetch(cloudInbox.dataset.cloudInboxSnapshotUrl, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            if (!response.ok) throw new Error(`Inbox snapshot failed with HTTP ${response.status}.`);

            const snapshot = await response.json();
            if (!selectedId && snapshot.conversations?.length > 0 && window.matchMedia('(min-width: 1024px)').matches) {
                window.location.assign(snapshot.conversations[0].show_url);
                return;
            }

            updateConversations(snapshot.conversations || [], snapshot.pagination);
            updateSelectedConversation(snapshot.selected);
            updateConversationMessages(snapshot.messages || []);
            failures = 0;
            setLiveStatus('live');
            schedule(baseDelay);
        } catch (error) {
            failures += 1;
            setLiveStatus('reconnecting');
            schedule(Math.min(baseDelay * (2 ** failures), maximumDelay));
        } finally {
            inFlight = false;
        }
    }

    function refreshWhenVisible() {
        if (document.visibilityState !== 'visible' || inFlight) return;
        window.clearTimeout(timer);
        poll();
    }

    document.addEventListener('visibilitychange', refreshWhenVisible);
    window.addEventListener('focus', refreshWhenVisible);
    replyText?.addEventListener('keydown', (event) => {
        const hasSendModifier = event.metaKey || event.ctrlKey;
        if (event.key !== 'Enter' || !hasSendModifier || event.shiftKey || event.altKey || event.isComposing) return;

        event.preventDefault();
        replyForm?.requestSubmit();
    });
    fileInput?.addEventListener('change', () => {
        const file = fileInput.files?.[0];
        if (file) showAttachment(file);
    });
    fileRemove?.addEventListener('click', resetAttachment);
    mediaForm?.addEventListener('submit', () => {
        if (mediaSubmit) {
            mediaSubmit.disabled = true;
            mediaSubmit.textContent = 'Sending…';
        }
    });
    cloudInbox.querySelectorAll('[data-cloud-inbox-media-image]').forEach((image) => {
        if (image.complete && image.naturalWidth === 0) showUnavailableImage(image);
        else image.addEventListener('error', () => showUnavailableImage(image));
    });
    cloudInbox.querySelectorAll('[data-cloud-inbox-media-audio]').forEach((audio) => {
        audio.addEventListener('error', () => showUnavailableAudio(audio));
    });
    if (messageList) {
        window.requestAnimationFrame(() => {
            messageList.scrollTop = messageList.scrollHeight;
        });
    }
    schedule(baseDelay);
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
