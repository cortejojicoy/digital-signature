/**
 * "Is this the computer your account is paired with?" — the browser half of
 * AgentPresenceService.
 *
 * Asks the server for a one-time check, opens its kukuxsign://presence link
 * (which wakes Kukux Sign Agent on this computer, if there is one), and polls
 * until the agent has reported in, said it belongs to another account, or the
 * check runs out. The agent talks to the server, never to this page.
 *
 * Reports progress through `onUpdate` because "nothing answered yet" is worth
 * showing well before the check expires: the browser may be waiting on its own
 * "Open Kukux Sign Agent?" prompt, and a late answer still counts.
 */

const POLL_MS = 1500;
const SETTLE_AFTER_MS = 12000;

function csrfToken() {
    return document.querySelector('meta[name=csrf-token]')?.content ?? '';
}

/**
 * @param {string} startUrl  POST endpoint that creates a check.
 * @param {(state: 'checking'|'waiting') => void} [onUpdate]
 *   'waiting' once nothing has answered for a while — show the "not this
 *   computer" message, but keep listening.
 * @returns {Promise<'here'|'elsewhere'|'other_account'|'unpaired'>}
 */
export async function checkAgentPresence(startUrl, onUpdate) {
    const res = await fetch(startUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
        },
    }).catch(() => null);

    if (res?.status === 409) return 'unpaired';
    if (!res?.ok) return 'elsewhere';

    const check = await res.json().catch(() => null);
    if (!check?.link || !check?.uuid) return 'elsewhere';

    onUpdate?.('checking');

    // A custom scheme leaves the page loaded; the OS hands it to the agent.
    window.location.assign(check.link);

    const statusUrl = `${startUrl.replace(/\/$/, '')}/${check.uuid}`;
    const started = Date.now();
    const deadline = started + (check.expires_in ?? 90) * 1000;
    let warned = false;

    while (Date.now() < deadline) {
        await new Promise((resolve) => setTimeout(resolve, POLL_MS));

        const poll = await fetch(statusUrl, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        }).catch(() => null);
        const body = poll?.ok ? await poll.json().catch(() => null) : null;

        if (body?.status === 'confirmed') return 'here';
        if (body?.status === 'other_account') return 'other_account';
        if (body?.status === 'expired') break;

        if (!warned && Date.now() - started > SETTLE_AFTER_MS) {
            warned = true;
            onUpdate?.('waiting');
        }
    }

    return 'elsewhere';
}
