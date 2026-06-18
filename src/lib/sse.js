export async function* parseSSE(body) {
    const reader  = body.getReader();
    const decoder = new TextDecoder();
    let buffer    = '';

    while (true) {
        const { done, value } = await reader.read();
        if (done) break;

        buffer += decoder.decode(value, { stream: true });
        const lines = buffer.split('\n');
        buffer = lines.pop() ?? '';

        for (const line of lines) {
            if (!line.startsWith('data: ')) continue;
            const raw = line.slice(6).trim();
            if (raw === '[DONE]') return;
            try {
                yield JSON.parse(raw);
            } catch {
                // skip malformed lines
            }
        }
    }
}
