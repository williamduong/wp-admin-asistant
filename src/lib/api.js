const { restUrl, nonce } = window.waaData ?? {};

async function readErrorMessage(response) {
    const contentType = response.headers.get('content-type') ?? '';

    if (contentType.includes('application/json')) {
        try {
            const payload = await response.json();
            return payload?.message
                ?? payload?.error
                ?? payload?.data?.message
                ?? `Request failed with status ${response.status}.`;
        } catch {
            return `Request failed with status ${response.status}.`;
        }
    }

    try {
        const text = (await response.text()).trim();
        return text || `Request failed with status ${response.status}.`;
    } catch {
        return `Request failed with status ${response.status}.`;
    }
}

export async function chatStream(message, history, conversationId, signal, confirmation = null, workflow = null) {
    const response = await fetch(`${restUrl}chat`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': nonce,
        },
        body: JSON.stringify({
            message,
            stream: true,
            history: history ?? [],
            conversation_id: conversationId ?? null,
            confirmation,
            workflow,
        }),
        signal,
    });

    if (!response.ok) {
        throw new Error(await readErrorMessage(response));
    }

    return response;
}

export async function apiFetch(path, options = {}) {
    const res = await fetch(`${restUrl}${path}`, {
        ...options,
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': nonce,
            ...(options.headers ?? {}),
        },
    });
    if (!res.ok) {
        throw new Error(await readErrorMessage(res));
    }
    return res.json();
}

// Conversation API
export const listConversations    = ()       => apiFetch('conversations');
export const createConversation   = (title, messages = [], history = [], usage = null, meta = null) => apiFetch('conversations', {
    method: 'POST',
    body: JSON.stringify({ title, messages, history, usage, meta }),
});
export const updateConversation   = (id, payload = {}) => apiFetch(`conversations/${id}`, {
    method: 'POST',
    body: JSON.stringify(payload),
});
export const loadConversation     = (id)     => apiFetch(`conversations/${id}`);
export const deleteConversation   = (id)     => apiFetch(`conversations/${id}`, { method: 'DELETE' });
export const archiveConversation = (id)     => updateConversation(id, { meta: { archived: true } });
