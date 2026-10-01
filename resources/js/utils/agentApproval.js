/**
 * "Approve on your computer": the browser half of a desktop-agent signing job.
 *
 * When a signer with a paired computer signs a document, the server pauses
 * and hands back an approval (HTTP 428 from the JSON endpoints, or the
 * `kukux-signature:agent-approval` event from Livewire). This module opens
 * the kukuxsign:// link, which wakes Kukux Sign Agent, shows a small overlay
 * while it polls the job, and reports how it ended so the caller can retry.
 *
 * The agent talks to the server, never to this page — see
 * digital-signature-agent/docs/protocol.md.
 */

const POLL_MS = 2000;
const HINT_AFTER_MS = 5000;

let active = null;

function csrfToken() {
    return document.querySelector('meta[name=csrf-token]')?.content ?? '';
}

function isDark() {
    return document.documentElement.classList.contains('dark')
        || (!document.documentElement.classList.contains('light')
            && window.matchMedia?.('(prefers-color-scheme: dark)').matches);
}

function el(tag, style, text) {
    const node = document.createElement(tag);
    if (style) node.style.cssText = style;
    if (text != null) node.textContent = text;
    return node;
}

function overlay(approval) {
    const dark = isDark();
    const fg = dark ? '#f4f4f5' : '#18181b';
    const muted = dark ? '#a1a1aa' : '#52525b';
    const bg = dark ? '#18181b' : '#ffffff';
    const border = dark ? 'rgb(255 255 255 / .12)' : 'rgb(0 0 0 / .1)';

    const backdrop = el('div', 'position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;'
        + 'justify-content:center;padding:16px;background:rgb(0 0 0 / .45);font-family:inherit;');
    backdrop.setAttribute('role', 'dialog');
    backdrop.setAttribute('aria-modal', 'true');
    backdrop.setAttribute('aria-live', 'polite');

    const card = el('div', `width:100%;max-width:380px;border-radius:12px;padding:20px;background:${bg};`
        + `color:${fg};border:1px solid ${border};box-shadow:0 20px 40px rgb(0 0 0 / .25);`);

    const title = el('p', 'margin:0 0 4px;font-size:15px;font-weight:600;',
        `Approve on ${approval.device ?? 'your computer'}`);
    const status = el('p', `margin:0;font-size:13px;line-height:1.5;color:${muted};`,
        `Kukux Sign Agent is asking you to confirm signing "${approval.title}".`);
    const hint = el('p', `margin:10px 0 0;font-size:12px;line-height:1.5;color:${muted};display:none;`);

    const actions = el('div', 'display:flex;flex-wrap:wrap;gap:8px;margin-top:16px;');
    const button = (label, primary) => {
        const b = el('button', 'cursor:pointer;border-radius:8px;padding:7px 12px;font-size:13px;font-weight:500;'
            + (primary
                ? `background:${fg};color:${bg};border:1px solid ${fg};`
                : `background:transparent;color:${fg};border:1px solid ${border};`), label);
        b.type = 'button';
        return b;
    };

    const reopen = button('Open the agent again', false);
    const skip = approval.skip_url ? button('Sign in the browser instead', false) : null;
    const cancel = button('Cancel', false);

    actions.append(reopen, ...(skip ? [skip] : []), cancel);
    card.append(title, status, hint, actions);
    backdrop.append(card);
    document.body.append(backdrop);

    return { backdrop, title, status, hint, actions, reopen, skip, cancel, button, muted };
}

function openAgent(link) {
    // A custom scheme leaves the page loaded; the OS asks to open the app.
    window.location.assign(link);
}

/**
 * Run one approval.
 *
 * @returns {Promise<'approved'|'skipped'|'rejected'|'expired'|'cancelled'>}
 */
export function runAgentApproval(approval) {
    if (active) return active;

    active = new Promise((resolve) => {
        const ui = overlay(approval);
        const started = Date.now();
        let timer = null;
        let finished = false;

        const finish = (outcome) => {
            if (finished) return;
            finished = true;
            clearTimeout(timer);
            ui.backdrop.remove();
            active = null;
            resolve(outcome);
        };

        // Rejected / expired: say so, and let the user close it.
        const endWith = (outcome, message) => {
            clearTimeout(timer);
            ui.title.textContent = outcome === 'rejected' ? 'Declined on your computer' : 'Approval expired';
            ui.status.textContent = message;
            ui.hint.style.display = 'none';
            ui.actions.replaceChildren();
            const close = ui.button('Close', true);
            close.addEventListener('click', () => finish(outcome));
            ui.actions.append(close);
            close.focus();
        };

        const poll = async () => {
            try {
                const res = await fetch(approval.status_url, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const body = res.ok ? await res.json() : null;

                if (body?.status === 'completed') return finish('approved');
                if (body?.status === 'rejected') {
                    return endWith('rejected', body.reason === 'os_prompt_cancelled'
                        ? 'The Touch ID / Windows Hello prompt was cancelled. Nothing was signed.'
                        : 'You declined this signature on your computer. Nothing was signed.');
                }
                if (body?.status === 'expired') {
                    return endWith('expired', 'The request timed out before it was approved. Nothing was signed.');
                }
                if (body?.status === 'claimed') {
                    ui.status.textContent = 'Confirm in the agent, then use Touch ID / Windows Hello…';
                    ui.hint.style.display = 'none';
                } else if (Date.now() - started > HINT_AFTER_MS) {
                    ui.hint.replaceChildren(
                        document.createTextNode('Nothing happening? Make sure Kukux Sign Agent is installed and running'
                            + (approval.download_url ? ' — ' : '.')),
                    );
                    if (approval.download_url) {
                        const a = el('a', 'color:inherit;text-decoration:underline;', 'download it');
                        a.href = approval.download_url;
                        a.target = '_blank';
                        a.rel = 'noopener';
                        ui.hint.append(a, document.createTextNode('.'));
                    }
                    ui.hint.style.display = 'block';
                }
            } catch (_) { /* transient; keep polling until the job expires */ }

            timer = setTimeout(poll, POLL_MS);
        };

        ui.reopen.addEventListener('click', () => openAgent(approval.link));
        ui.cancel.addEventListener('click', () => finish('cancelled'));
        ui.skip?.addEventListener('click', async () => {
            ui.skip.disabled = true;
            const res = await fetch(approval.skip_url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
            }).catch(() => null);
            if (res?.ok) finish('skipped');
            else ui.skip.disabled = false;
        });

        openAgent(approval.link);
        timer = setTimeout(poll, POLL_MS);
    });

    return active;
}

const OUTCOME_ERRORS = {
    cancelled: 'Signing was cancelled.',
    rejected: 'Declined on your computer. Nothing was signed.',
    expired: 'The approval request expired. Try signing again.',
};

/**
 * Wrap a JSON signing request: when the server answers 428 with an
 * approval, run it and send the same request again.
 *
 * @param {() => Promise<Response>} send  Builds and sends the request; called again on retry.
 */
export async function fetchWithAgentApproval(send) {
    let res = await send();

    // Twice at most: one approval, plus one more if it expired mid-flight.
    for (let attempt = 0; attempt < 2 && res.status === 428; attempt++) {
        const body = await res.clone().json().catch(() => null);
        if (!body?.agent_approval) break;

        const outcome = await runAgentApproval(body.agent_approval);
        if (outcome !== 'approved' && outcome !== 'skipped') {
            return new Response(JSON.stringify({ error: OUTCOME_ERRORS[outcome] }), {
                status: 409,
                headers: { 'Content-Type': 'application/json' },
            });
        }

        res = await send();
    }

    return res;
}

/**
 * Livewire surfaces (Filament actions, the inbox) dispatch the approval as a
 * browser event, with the component method to call again once it's granted.
 */
export function listenForLivewireApprovals() {
    window.addEventListener('kukux-signature:agent-approval', async (event) => {
        const { approval, retry } = event.detail ?? {};
        if (!approval) return;

        const outcome = await runAgentApproval(approval);

        if ((outcome === 'approved' || outcome === 'skipped') && retry?.component) {
            window.Livewire?.find(retry.component)?.call(retry.method, ...(retry.params ?? []));
        }
    });
}
