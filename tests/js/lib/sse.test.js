import { describe, expect, it } from 'vitest';
import { parseSSE } from '../../../src/lib/sse';

function makeBody(chunks) {
    let index = 0;

    return {
        getReader() {
            return {
                async read() {
                    if (index >= chunks.length) {
                        return { done: true, value: undefined };
                    }

                    const encoder = new TextEncoder();
                    return { done: false, value: encoder.encode(chunks[index++]) };
                },
            };
        },
    };
}

describe('parseSSE', () => {
    it('parses JSON events split across multiple chunks', async () => {
        const body = makeBody([
            'data: {"type":"text_',
            'delta","content":"Hello"}\n',
            '\n',
            'data: {"type":"done"}\n\n',
        ]);

        const events = [];
        for await (const event of parseSSE(body)) {
            events.push(event);
        }

        expect(events).toEqual([
            { type: 'text_delta', content: 'Hello' },
            { type: 'done' },
        ]);
    });

    it('stops when it receives the done sentinel and skips malformed payloads', async () => {
        const body = makeBody([
            'event: ping\n',
            'data: not-json\n\n',
            'data: {"type":"usage","input_tokens":1}\n\n',
            'data: [DONE]\n\n',
            'data: {"type":"text_delta","content":"ignored"}\n\n',
        ]);

        const events = [];
        for await (const event of parseSSE(body)) {
            events.push(event);
        }

        expect(events).toEqual([
            { type: 'usage', input_tokens: 1 },
        ]);
    });
});
