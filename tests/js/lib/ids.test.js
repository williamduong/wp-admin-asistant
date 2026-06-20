import { describe, expect, it, vi, afterEach } from 'vitest';
import { makeClientId } from '../../../src/lib/ids';

describe('makeClientId', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('uses crypto.randomUUID when available', () => {
        vi.stubGlobal('crypto', {
            randomUUID: vi.fn(() => 'uuid-from-randomuuid'),
        });

        expect(makeClientId()).toBe('uuid-from-randomuuid');
    });

    it('falls back to crypto.getRandomValues when randomUUID is unavailable', () => {
        vi.stubGlobal('crypto', {
            getRandomValues: (bytes) => {
                for (let i = 0; i < bytes.length; i += 1) {
                    bytes[i] = i;
                }
                return bytes;
            },
        });

        expect(makeClientId()).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    });

    it('falls back to a timestamp-based id when crypto is unavailable', () => {
        vi.stubGlobal('crypto', undefined);

        expect(makeClientId()).toMatch(/^waa-/);
    });
});
