import { expect } from 'vitest';
import * as matchers from '@testing-library/jest-dom/matchers';

expect.extend(matchers);
global.expect = expect;

const storage = new Map();
const localStorageMock = {
    getItem: (key) => storage.has(key) ? storage.get(key) : null,
    setItem: (key, value) => {
        storage.set(String(key), String(value));
    },
    removeItem: (key) => {
        storage.delete(String(key));
    },
    clear: () => {
        storage.clear();
    },
};

Object.defineProperty(globalThis, 'localStorage', {
    value: localStorageMock,
    configurable: true,
});

// Mock WordPress localized data
global.waaData = {
    nonce:       'test-nonce-123',
    restUrl:     'http://localhost/wp-json/wp-admin-agent/v1/',
    currentUser: { id: 1, name: 'Admin' },
    siteUrl:     'http://localhost',
    version:     '0.1.0',
};

// Mock crypto.randomUUID in jsdom
if (!global.crypto) {
    global.crypto = {};
}
if (!global.crypto.randomUUID) {
    let counter = 0;
    global.crypto.randomUUID = () => `test-uuid-${++counter}`;
}
