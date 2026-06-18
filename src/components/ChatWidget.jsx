import { useState, useRef, useEffect } from 'react';
import { useChat }            from '../hooks/useChat';
import MessageList            from './MessageList';
import InputBar               from './InputBar';
import TypingIndicator        from './TypingIndicator';
import QuickPrompts           from './QuickPrompts';
import SessionStats           from './SessionStats';
import ConversationManager    from './ConversationManager';
import NavToast               from './NavToast';
import TaskRail               from './TaskRail';
import MultiQuestionForm      from './MultiQuestionForm';
import styles                 from '../styles/widget.module.css';
import { getWorkflowDefinition } from '../lib/workflows';

function findLatestQueuedTask(messages) {
    for (let i = messages.length - 1; i >= 0; i -= 1) {
        const toolCalls = messages[i].toolCalls ?? [];
        for (let j = toolCalls.length - 1; j >= 0; j -= 1) {
            if (toolCalls[j].status === 'queued') {
                return toolCalls[j];
            }
        }
    }

    return null;
}

export default function ChatWidget({ isOpen, onToggle }) {
    const {
        messages, isLoading, activeToolName, sessionUsage, apiHistory, conversationId,
        pendingConfirmation, confirmPendingAction, cancelPendingAction,
        pendingNavUrl, clearNavUrl,
        activeWorkflow, startWorkflow, cancelWorkflow, updateWorkflowAnswer,
        goToNextWorkflowStep, goToPreviousWorkflowStep, setWorkflowStep, submitWorkflow,
        sendMessage, clearMessages, loadMessages,
    } = useChat();

    const [view, setView] = useState('chat'); // 'chat' | 'conversations'
    const panelRef = useRef(null);
    const latestQueuedTask = findLatestQueuedTask(messages);
    const activeWorkflowDefinition = activeWorkflow ? getWorkflowDefinition(activeWorkflow.workflowId) : null;

    // Close on Escape
    useEffect(() => {
        const handler = (e) => { if (e.key === 'Escape' && isOpen) onToggle(); };
        window.addEventListener('keydown', handler);
        return () => window.removeEventListener('keydown', handler);
    }, [isOpen, onToggle]);

    const handleNewSession = () => {
        clearMessages();
        setView('chat');
    };

    return (
        <>
            {/* Toggle button */}
            <button
                className={styles.toggleBtn}
                onClick={onToggle}
                aria-label={isOpen ? 'Close AI assistant' : 'Open AI assistant'}
                title="AI Assistant"
            >
                {isOpen ? '✕' : '🤖'}
            </button>

            {/* Chat panel */}
            {isOpen && (
                <div className={styles.panel} ref={panelRef} role="dialog" aria-label="AI Assistant">
                    <div className={styles.header}>
                        <span className={styles.headerTitle}>🤖 Admin Assistant</span>
                        <div className={styles.headerActions}>
                            <button
                                className={styles.iconBtn}
                                onClick={() => startWorkflow('woocommerce_first_time_setup')}
                                title="Start WooCommerce setup wizard"
                            >
                                🧭
                            </button>
                            <button
                                className={styles.iconBtn}
                                onClick={() => setView(v => v === 'conversations' ? 'chat' : 'conversations')}
                                title="Session history"
                                aria-pressed={view === 'conversations'}
                            >
                                📂
                            </button>
                            <button className={styles.clearBtn} onClick={handleNewSession} title="Start a new session">
                                New
                            </button>
                        </div>
                    </div>

                    {view === 'conversations' ? (
                        <ConversationManager
                            currentConversationId={conversationId}
                            onLoad={(msgs, usage, history, id, meta) => { loadMessages(msgs, usage, history, id, meta); setView('chat'); }}
                            onClose={() => setView('chat')}
                        />
                    ) : (
                        <>
                            {activeWorkflow && activeWorkflowDefinition && (
                                <MultiQuestionForm
                                    definition={activeWorkflowDefinition}
                                    workflow={activeWorkflow}
                                    isLoading={isLoading}
                                    onUpdateAnswer={updateWorkflowAnswer}
                                    onNext={goToNextWorkflowStep}
                                    onBack={goToPreviousWorkflowStep}
                                    onSelectStep={setWorkflowStep}
                                    onCancel={cancelWorkflow}
                                    onSubmit={submitWorkflow}
                                />
                            )}

                            <TaskRail
                                pendingConfirmation={pendingConfirmation}
                                activeToolName={activeToolName}
                                isLoading={isLoading}
                                queuedTask={latestQueuedTask}
                                onConfirmPendingAction={confirmPendingAction}
                                onCancelPendingAction={cancelPendingAction}
                            />

                            <MessageList messages={messages} />

                            {messages.length === 0 && (
                                <QuickPrompts onSelect={sendMessage} />
                            )}

                            {isLoading && <TypingIndicator toolName={activeToolName} />}

                            {pendingNavUrl && !isLoading && (
                                <NavToast url={pendingNavUrl} onDismiss={clearNavUrl} />
                            )}

                            <SessionStats usage={sessionUsage} />

                            <InputBar
                                onSend={sendMessage}
                                isLoading={isLoading}
                            />

                    <div className={styles.copyright}>
                        By <a href="https://williamresearch.com/" target="_blank" rel="noopener noreferrer">William GoRight</a>
                    </div>
                        </>
                    )}
                </div>
            )}
        </>
    );
}
