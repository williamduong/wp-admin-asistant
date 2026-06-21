import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ChatWidget from '../../../src/components/ChatWidget';

const useChatMock = vi.fn();

vi.mock('../../../src/hooks/useChat', () => ({
    useChat: () => useChatMock(),
}));

vi.mock('../../../src/components/MessageList', () => ({
    default: () => <div>MessageList</div>,
}));

vi.mock('../../../src/components/InputBar', () => ({
    default: () => <div>InputBar</div>,
}));

vi.mock('../../../src/components/TypingIndicator', () => ({
    default: () => <div>TypingIndicator</div>,
}));

vi.mock('../../../src/components/QuickPrompts', () => ({
    default: () => <div>QuickPrompts</div>,
}));

vi.mock('../../../src/components/SessionStats', () => ({
    default: () => <div>SessionStats</div>,
}));

vi.mock('../../../src/components/ConversationManager', () => ({
    default: () => <div>ConversationManager</div>,
}));

vi.mock('../../../src/components/NavToast', () => ({
    default: () => <div>NavToast</div>,
}));

vi.mock('../../../src/components/TaskRail', () => ({
    default: () => <div>TaskRail</div>,
}));

vi.mock('../../../src/components/MultiQuestionForm', () => ({
    default: () => <div>MultiQuestionForm</div>,
}));

describe('ChatWidget', () => {
    const buildChatState = (overrides = {}) => ({
        messages: [],
        isLoading: false,
        activeToolName: null,
        sessionUsage: { input_tokens: 0, output_tokens: 0, cost_usd: 0, elapsed_ms: 0 },
        apiHistory: [],
        conversationId: 321,
        pendingConfirmation: null,
        confirmPendingAction: vi.fn(),
        cancelPendingAction: vi.fn(),
        pendingNavUrl: null,
        clearNavUrl: vi.fn(),
        activeWorkflow: null,
        startWorkflow: vi.fn(),
        cancelWorkflow: vi.fn(),
        updateWorkflowAnswer: vi.fn(),
        goToNextWorkflowStep: vi.fn(),
        goToPreviousWorkflowStep: vi.fn(),
        setWorkflowStep: vi.fn(),
        submitWorkflow: vi.fn(),
        sendMessage: vi.fn(),
        clearMessages: vi.fn(),
        loadMessages: vi.fn(),
        ...overrides,
    });

    beforeEach(() => {
        useChatMock.mockReset();
        useChatMock.mockReturnValue(buildChatState());
    });

    afterEach(() => {
        cleanup();
    });

    it('shows the session id in the chat header when a conversation exists', () => {
        render(<ChatWidget isOpen={true} onToggle={vi.fn()} />);

        expect(screen.getByText('🤖 Admin Assistant')).toBeInTheDocument();
        expect(screen.getByText('Session #321')).toBeInTheDocument();
    });

    it('shows a pending session label before a conversation id exists', () => {
        useChatMock.mockReturnValue(buildChatState({ conversationId: null }));

        render(<ChatWidget isOpen={true} onToggle={vi.fn()} />);

        expect(screen.getByText('Session chưa được tạo')).toBeInTheDocument();
    });

    it('can switch to conversation history view from the header action', () => {
        render(<ChatWidget isOpen={true} onToggle={vi.fn()} />);

        fireEvent.click(screen.getByTitle('Session history'));

        expect(screen.getByText('ConversationManager')).toBeInTheDocument();
    });
});
