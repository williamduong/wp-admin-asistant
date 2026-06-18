import { useState, useCallback, useRef, useEffect } from 'react';
import { chatStream, createConversation, updateConversation } from '../lib/api';
import { parseSSE }   from '../lib/sse';
import {
    createWorkflowState,
    getWorkflowCurrentStep,
    getWorkflowDefinition,
    getNextWorkflowStep,
    getPreviousWorkflowStep,
    getVisibleWorkflowSteps,
    isWorkflowReady,
    matchWorkflowLaunchCommand,
} from '../lib/workflows';

const STORAGE_KEY = 'waa_chat_v1';
const EMPTY_USAGE = { input_tokens: 0, output_tokens: 0, cost_usd: 0, elapsed_ms: 0 };

function toAbsoluteUrl(rawUrl) {
    if (typeof rawUrl !== 'string' || !rawUrl.trim()) {
        return null;
    }
    const base = typeof window !== 'undefined' ? window.location.href : '';
    try {
        return new URL(rawUrl, base);
    } catch {
        return null;
    }
}

function areSameBrowserDestination(left, right) {
    const leftUrl = toAbsoluteUrl(left);
    const rightUrl = toAbsoluteUrl(right);
    if (!leftUrl || !rightUrl) {
        return leftUrl === rightUrl;
    }

    const normalize = (url) => {
        const pathname = url.pathname.replace(/\/+$/, '');
        return `${url.origin}${pathname}${url.search}`;
    };

    return normalize(leftUrl) === normalize(rightUrl);
}

function shouldShowNavigateToast(rawUrl, currentUrl, pendingUrl = null) {
    if (!rawUrl) {
        return false;
    }
    if (areSameBrowserDestination(rawUrl, currentUrl)) {
        return false;
    }
    return !areSameBrowserDestination(rawUrl, pendingUrl);
}

function normalizePendingConfirmation(event) {
    const details = event.confirmation ?? {};

    return {
        approved: true,
        tool_name: event.tool_name,
        tool_use_id: event.tool_use_id,
        tool_input: event.tool_input ?? {},
        message: details.summary ?? event.message ?? '',
        title: details.title ?? 'Approve change',
        summary: details.summary ?? event.message ?? '',
        impact: details.impact ?? '',
        riskLevel: details.risk_level ?? 'sensitive',
        actionType: details.action_type ?? 'write',
        isAsync: Boolean(details.is_async),
        confirmLabel: details.confirm_label ?? 'Confirm change',
        cancelLabel: details.cancel_label ?? 'Cancel action',
        currentState: details.current_state ?? null,
        proposedState: details.proposed_state ?? null,
    };
}

function getToolCallStatus(result) {
    if (result?.async && result?.job_status) {
        return result.job_status;
    }

    return 'done';
}

function getToolCallMeta(result) {
    if (!result?.async) {
        return null;
    }

    return {
        isAsync: true,
        jobType: result.job_type ?? 'background_job',
        jobStatus: result.job_status ?? 'queued',
        followUpTool: result.recommended_poll_tool ?? null,
        followUpDelaySec: result.recommended_delay_sec ?? null,
        message: result.message ?? '',
    };
}

function isToolFailureResult(result) {
    return Boolean(result?.error) || result?.success === false;
}

function isQueuedAsyncResult(result) {
    return Boolean(result?.async) && (result?.job_status ?? '') === 'queued';
}

function guardAssistantTurnContent(text, toolResults) {
    const baseText = text ?? '';
    const normalized = baseText.trim();
    const hasFailure = toolResults.some((entry) => isToolFailureResult(entry.result));
    const hasQueuedTask = toolResults.some((entry) => isQueuedAsyncResult(entry.result));

    if (hasFailure) {
        if (!normalized) {
            return 'The requested action did not complete successfully.';
        }

        if (/(confirmed|completed|done|successful|succeeded|all set)/i.test(normalized) && !/(fail|error|could not|unable|did not)/i.test(normalized)) {
            return `${normalized}\n\nOne or more tool steps failed. Review the error details above.`;
        }

        return baseText;
    }

    if (hasQueuedTask) {
        if (!normalized) {
            return 'The requested action has only been queued so far and is still waiting to finish in the background.';
        }

        if (/(confirmed|completed|done|finished|all set)/i.test(normalized) && !/(queued|background|waiting|in progress|wp-cron)/i.test(normalized)) {
            return `${normalized}\n\nThe requested action has only been queued so far and is still waiting to finish in the background.`;
        }
    }

    return baseText;
}

function loadFromStorage() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        return raw ? JSON.parse(raw) : { messages: [], history: [], usage: EMPTY_USAGE, conversationId: null, pendingConfirmation: null, activeWorkflow: null };
    } catch {
        return { messages: [], history: [], usage: EMPTY_USAGE, conversationId: null, pendingConfirmation: null, activeWorkflow: null };
    }
}

export function useChat() {
    const stored = loadFromStorage;
    const [messages,       setMessages]       = useState(() => stored().messages);
    const [apiHistory,     setApiHistory]     = useState(() => stored().history ?? []);
    const [isLoading,      setIsLoading]      = useState(false);
    const [activeToolName, setActiveToolName] = useState(null);
    const [sessionUsage,   setSessionUsage]   = useState(() => stored().usage);
    const [conversationId, setConversationId] = useState(() => stored().conversationId ?? null);
    const [pendingNavUrl,  setPendingNavUrl]  = useState(null);
    const [pendingConfirmation, setPendingConfirmation] = useState(() => stored().pendingConfirmation ?? null);
    const [activeWorkflow, setActiveWorkflow] = useState(() => stored().activeWorkflow ?? stored().activeWizard ?? null);
    const abortRef = useRef(null);
    const skipNextPersistRef = useRef(false);
    const lastWorkflowSyncRef = useRef({ conversationId: null, serialized: null });

    // Persist display messages + api history + usage to localStorage
    useEffect(() => {
        if (skipNextPersistRef.current) {
            skipNextPersistRef.current = false;
            return;
        }
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify({
                messages,
                history: apiHistory,
                usage: sessionUsage,
                conversationId,
                pendingConfirmation,
                activeWorkflow,
            }));
        } catch {}
    }, [messages, apiHistory, sessionUsage, conversationId, pendingConfirmation, activeWorkflow]);

    useEffect(() => {
        if (!conversationId) {
            lastWorkflowSyncRef.current = { conversationId: null, serialized: null };
            return;
        }

        const serialized = JSON.stringify(activeWorkflow ?? null);
        if (lastWorkflowSyncRef.current.conversationId !== conversationId) {
            lastWorkflowSyncRef.current = { conversationId, serialized };
            if (activeWorkflow == null) {
                return;
            }
        }

        if (lastWorkflowSyncRef.current.serialized === serialized) {
            return;
        }
        lastWorkflowSyncRef.current = { conversationId, serialized };

        const request = updateConversation(conversationId, {
            meta: { active_workflow: activeWorkflow },
        });
        request?.catch?.(() => {});
    }, [activeWorkflow, conversationId]);

    const sendMessage = useCallback(async (text, options = {}) => {
        const bypassWorkflowCommands = Boolean(options.bypassWorkflowCommands);
        const launchWorkflowId = !bypassWorkflowCommands ? matchWorkflowLaunchCommand(text) : null;
        if (launchWorkflowId) {
            setPendingConfirmation(null);
            setPendingNavUrl(null);
            setActiveWorkflow(createWorkflowState(launchWorkflowId));
            return;
        }

        if (!bypassWorkflowCommands && text.trim().toLowerCase() === '/workflow cancel') {
            setActiveWorkflow(null);
            return;
        }

        if (!bypassWorkflowCommands && text.trim().toLowerCase() === '/workflow submit' && activeWorkflow) {
            const definition = getWorkflowDefinition(activeWorkflow.workflowId);
            if (definition && isWorkflowReady(definition, activeWorkflow)) {
                const prompt = definition.buildApplyPrompt(activeWorkflow.answers);
                const applyingWorkflow = {
                    ...activeWorkflow,
                    status: 'applying',
                };
                setActiveWorkflow(applyingWorkflow);
                await sendMessage(prompt, { workflow: applyingWorkflow, bypassWorkflowCommands: true });
                setActiveWorkflow(null);
            }
            return;
        }

        const userId    = crypto.randomUUID();
        const assistId  = crypto.randomUUID();
        const startTime = Date.now();
        const confirmation = options.confirmation ?? null;
        const workflow = options.workflow ?? activeWorkflow ?? null;

        setPendingNavUrl(null);
        setPendingConfirmation(null);
        setMessages(prev => [
            ...prev,
            { role: 'user',      content: text,  id: userId },
            { role: 'assistant', content: '', toolCalls: [], id: assistId },
        ]);
        setIsLoading(true);

        abortRef.current?.abort();
        abortRef.current = new AbortController();

        let collectedNavUrl = null;
        let resolvedConversationId = conversationId;

        // Snapshot history sent to server
        const historyToSend = apiHistory;

        // Track current turn for history building
        let displayText       = '';
        let displayToolCalls  = [];
        let finalMessageConfirmation = null;
        let terminalErrorMessage = '';
        let currentText       = '';
        let currentToolCalls  = []; // [{id, name, input}]
        let currentToolResults = []; // [{tool_call_id, tool_name, result}]
        const historyChunks   = []; // completed iterations (assistant + tool messages)
        let iterationHasTools = false;
        let firstUsageSeen    = false;
        let streamedUsage     = { ...sessionUsage };

        if (!resolvedConversationId) {
            try {
                const created = await createConversation(
                    text.slice(0, 60) + (text.length > 60 ? '…' : ''),
                    [],
                    historyToSend,
                    sessionUsage,
                    { active_workflow: workflow }
                );
                resolvedConversationId = created.id ?? null;
                if (resolvedConversationId) {
                    setConversationId(resolvedConversationId);
                }
            } catch {
                // Live persistence is best-effort; the browser session still continues.
            }
        }

        try {
            const response = await chatStream(
                text,
                historyToSend,
                resolvedConversationId,
                abortRef.current.signal,
                confirmation,
                workflow
            );

            for await (const event of parseSSE(response.body)) {
                switch (event.type) {
                    case 'text_delta':
                        displayText += event.content;
                        currentText += event.content;
                        setMessages(prev => prev.map(m =>
                            m.id === assistId ? { ...m, content: m.content + event.content } : m
                        ));
                        break;

                    case 'tool_start':
                        setActiveToolName(event.tool_name);
                        currentToolCalls.push({
                            id:    event.tool_use_id,
                            name:  event.tool_name,
                            input: event.tool_input ?? {},
                        });
                        displayToolCalls.push({
                            tool_use_id: event.tool_use_id,
                            name: event.tool_name,
                            input: event.tool_input ?? {},
                            status: 'running',
                        });
                        iterationHasTools = true;
                        setMessages(prev => prev.map(m =>
                            m.id === assistId
                                ? { ...m, toolCalls: [...m.toolCalls, {
                                    tool_use_id: event.tool_use_id,
                                    name:   event.tool_name,
                                    input:  event.tool_input ?? {},
                                    status: 'running',
                                }] }
                                : m
                        ));
                        break;

                    case 'tool_end':
                        setActiveToolName(null);
                        currentToolResults.push({
                            tool_call_id: event.tool_use_id,
                            tool_name:    event.tool_name,
                            result:       event.result,
                        });
                        displayToolCalls = displayToolCalls.map(tc =>
                            tc.tool_use_id === event.tool_use_id
                                ? (() => {
                                    const asyncMeta = getToolCallMeta(event.result);
                                    return {
                                        ...tc,
                                        status: getToolCallStatus(event.result),
                                        result: event.result,
                                        ...(asyncMeta ? { asyncMeta } : {}),
                                    };
                                })()
                                : tc
                        );
                        setMessages(prev => prev.map(m =>
                            m.id === assistId
                                ? { ...m, toolCalls: m.toolCalls.map(tc =>
                                    tc.tool_use_id === event.tool_use_id
                                        ? (() => {
                                            const asyncMeta = getToolCallMeta(event.result);
                                            return {
                                                ...tc,
                                                status: getToolCallStatus(event.result),
                                                result: event.result,
                                                ...(asyncMeta ? { asyncMeta } : {}),
                                            };
                                        })()
                                        : tc
                                )}
                                : m
                        ));
                        break;

                    case 'navigate':
                        if (shouldShowNavigateToast(event.url, window.location.href, collectedNavUrl)) {
                            collectedNavUrl = event.url;
                        }
                        break;

                    case 'confirmation_required':
                        {
                            const confirmation = normalizePendingConfirmation(event);
                            finalMessageConfirmation = {
                                title: confirmation.title,
                                summary: confirmation.summary,
                                impact: confirmation.impact,
                                toolName: event.tool_name,
                                riskLevel: confirmation.riskLevel,
                                status: 'pending',
                            };
                            setPendingConfirmation(confirmation);
                            if (!currentText.trim()) {
                                currentText = confirmation.summary || 'This action is waiting for your confirmation.';
                                displayText = displayText.trim()
                                    ? `${displayText}\n\n${currentText}`
                                    : currentText;
                                setMessages(prev => prev.map(m =>
                                    m.id === assistId
                                        ? {
                                            ...m,
                                            content: currentText,
                                            confirmation: finalMessageConfirmation,
                                        }
                                        : m
                                ));
                            } else {
                                if (confirmation.summary && !displayText.includes(confirmation.summary)) {
                                    displayText = `${displayText}\n\n${confirmation.summary}`;
                                }
                                setMessages(prev => prev.map(m =>
                                    m.id === assistId
                                        ? {
                                            ...m,
                                            confirmation: finalMessageConfirmation,
                                        }
                                        : m
                                ));
                            }
                        }
                        break;

                    case 'usage':
                        // Each 'usage' marks the start of a new LLM iteration.
                        // Flush the previous iteration's tool calls into history chunks.
                        if (firstUsageSeen && iterationHasTools) {
                            historyChunks.push(
                                { role: 'assistant', content: currentText, tool_calls: currentToolCalls },
                                ...currentToolResults.map(tr => ({
                                    role: 'tool', tool_call_id: tr.tool_call_id,
                                    tool_name: tr.tool_name, result: tr.result,
                                }))
                            );
                            currentText        = '';
                            currentToolCalls   = [];
                            currentToolResults = [];
                            iterationHasTools  = false;
                        }
                        firstUsageSeen = true;
                        streamedUsage = {
                            ...streamedUsage,
                            input_tokens: event.input_tokens,
                            output_tokens: event.output_tokens,
                            cost_usd: event.cost_usd,
                        };
                        setSessionUsage(prev => ({
                            ...prev,
                            input_tokens:  event.input_tokens,
                            output_tokens: event.output_tokens,
                            cost_usd:      event.cost_usd,
                        }));
                        break;

                    case 'error':
                        setMessages(prev => prev.map(m =>
                            m.id === assistId
                                ? { ...m, content: `Error: ${event.message}`, isError: true }
                                : m
                        ));
                        break;
                }
            }
        } catch (err) {
            if (err.name !== 'AbortError') {
                terminalErrorMessage = err.message?.trim() || 'Connection error. Please try again.';
                setMessages(prev => prev.map(m =>
                    m.id === assistId
                        ? { ...m, content: terminalErrorMessage, isError: true }
                        : m
                ));
            }
        } finally {
            setIsLoading(false);
            setActiveToolName(null);
            if (collectedNavUrl) setPendingNavUrl(collectedNavUrl);
            const nextUsage = { ...streamedUsage, elapsed_ms: Date.now() - startTime };
            setSessionUsage(prev => ({ ...prev, elapsed_ms: nextUsage.elapsed_ms }));

            // Append this full turn (user + all iterations + final assistant) to API history
            const toolResultMessages = currentToolResults.map(tr => ({
                role: 'tool', tool_call_id: tr.tool_call_id,
                tool_name: tr.tool_name, result: tr.result,
            }));
            // Only include the final assistant entry if it has content or tool calls
            const finalAssistant = (currentText.trim() || currentToolCalls.length > 0)
                ? [{ role: 'assistant', content: currentText, tool_calls: currentToolCalls }]
                : [];
            const finalDisplayText = displayText || terminalErrorMessage;
            const guardedAssistantContent = guardAssistantTurnContent(finalDisplayText, currentToolResults);
            const nextHistory = [
                ...historyToSend,
                { role: 'user', content: text },
                ...historyChunks,
                ...finalAssistant,
                ...toolResultMessages,
            ];
            const nextMessages = [
                ...messages,
                { role: 'user', content: text, id: userId },
                {
                    role: 'assistant',
                    content: `${guardedAssistantContent}`,
                    toolCalls: displayToolCalls,
                    ...(terminalErrorMessage ? { isError: true } : {}),
                    ...(finalMessageConfirmation ? { confirmation: finalMessageConfirmation } : {}),
                    id: assistId,
                },
            ];

            setMessages(nextMessages);
            setApiHistory(nextHistory);

            if (resolvedConversationId) {
                try {
                    await updateConversation(resolvedConversationId, {
                        messages: nextMessages,
                        history: nextHistory,
                        usage: nextUsage,
                        meta: { active_workflow: workflow?.status === 'applying' ? null : workflow },
                    });
                } catch {
                    // Leave local browser session intact even if persistence fails.
                }
            }
        }
    }, [activeWorkflow, apiHistory, conversationId, messages, sessionUsage]);

    const clearMessages = useCallback(() => {
        skipNextPersistRef.current = true;
        setMessages([]);
        setApiHistory([]);
        setSessionUsage(EMPTY_USAGE);
        setConversationId(null);
        setPendingNavUrl(null);
        setPendingConfirmation(null);
        setActiveWorkflow(null);
        try { localStorage.removeItem(STORAGE_KEY); } catch {}
    }, []);

    const loadMessages = useCallback((savedMessages, savedUsage, savedHistory = [], savedConversationId = null, savedMeta = null) => {
        setMessages(savedMessages ?? []);
        setApiHistory(savedHistory ?? []);
        setSessionUsage(savedUsage ?? EMPTY_USAGE);
        setConversationId(savedConversationId ?? null);
        setPendingNavUrl(null);
        setPendingConfirmation(null);
        setActiveWorkflow(savedMeta?.active_workflow ?? null);
    }, []);

    const confirmPendingAction = useCallback(async () => {
        if (!pendingConfirmation || isLoading) {
            return;
        }

        await sendMessage('Yes, proceed.', { confirmation: pendingConfirmation });
    }, [isLoading, pendingConfirmation, sendMessage]);

    const cancelPendingAction = useCallback(async () => {
        if (!pendingConfirmation || isLoading) {
            return;
        }

        const userId = crypto.randomUUID();
        const assistId = crypto.randomUUID();
        const cancelText = 'No, cancel that action.';
        const assistantText = 'Okay, I will not run that action.';
        const nextMessages = [
            ...messages,
            { role: 'user', content: cancelText, id: userId },
            { role: 'assistant', content: assistantText, toolCalls: [], id: assistId },
        ];
        const nextHistory = [
            ...apiHistory,
            { role: 'user', content: cancelText },
            { role: 'assistant', content: assistantText, tool_calls: [] },
        ];

        setMessages(nextMessages);
        setApiHistory(nextHistory);
        setPendingConfirmation(null);

        if (conversationId) {
            try {
                await updateConversation(conversationId, {
                    messages: nextMessages,
                    history: nextHistory,
                    usage: sessionUsage,
                    meta: { active_workflow: activeWorkflow },
                });
            } catch {
                // Keep local cancellation state even if persistence fails.
            }
        }
    }, [activeWorkflow, apiHistory, conversationId, isLoading, messages, pendingConfirmation, sessionUsage]);

    const startWorkflow = useCallback((workflowId) => {
        setPendingConfirmation(null);
        setActiveWorkflow(createWorkflowState(workflowId));
    }, []);

    const cancelWorkflow = useCallback(() => {
        setActiveWorkflow(null);
    }, []);

    const updateWorkflowAnswer = useCallback((key, value) => {
        setActiveWorkflow((prev) => {
            if (!prev || prev.kind !== 'wizard') {
                return prev;
            }

            const answers = {
                ...prev.answers,
                [key]: value,
            };

            if (key === 'sampleProductEnabled' && !value) {
                answers.sampleProductName = '';
                answers.sampleProductPrice = '';
            }

            const definition = getWorkflowDefinition(prev.workflowId);
            const currentStep = definition && definition.steps.some((step) => step.key === prev.currentStep)
                ? prev.currentStep
                : definition?.steps[0]?.key ?? null;

            return {
                ...prev,
                answers,
                currentStep,
            };
        });
    }, []);

    const goToNextWorkflowStep = useCallback(() => {
        setActiveWorkflow((prev) => {
            if (!prev || prev.kind !== 'wizard') {
                return prev;
            }

            const definition = getWorkflowDefinition(prev.workflowId);
            if (!definition) {
                return prev;
            }

            const nextStep = getNextWorkflowStep(definition, prev);

            return {
                ...prev,
                currentStep: nextStep?.key ?? prev.currentStep,
            };
        });
    }, []);

    const goToPreviousWorkflowStep = useCallback(() => {
        setActiveWorkflow((prev) => {
            if (!prev || prev.kind !== 'wizard') {
                return prev;
            }

            const definition = getWorkflowDefinition(prev.workflowId);
            if (!definition) {
                return prev;
            }

            const previousStep = getPreviousWorkflowStep(definition, prev);

            return {
                ...prev,
                currentStep: previousStep?.key ?? prev.currentStep,
            };
        });
    }, []);

    const setWorkflowStep = useCallback((stepKey) => {
        setActiveWorkflow((prev) => {
            if (!prev || prev.kind !== 'wizard') {
                return prev;
            }

            const definition = getWorkflowDefinition(prev.workflowId);
            const visibleSteps = definition ? getVisibleWorkflowSteps(definition, prev.answers) : [];
            if (!visibleSteps.some((step) => step.key === stepKey)) {
                return prev;
            }

            return {
                ...prev,
                currentStep: stepKey,
            };
        });
    }, []);

    const submitWorkflow = useCallback(async () => {
        if (!activeWorkflow || activeWorkflow.kind !== 'wizard') {
            return;
        }

        const definition = getWorkflowDefinition(activeWorkflow.workflowId);
        if (!definition || !isWorkflowReady(definition, activeWorkflow)) {
            return;
        }

        const prompt = definition.buildApplyPrompt(activeWorkflow.answers);
        const applyingWorkflow = {
            ...activeWorkflow,
            status: 'applying',
        };
        setActiveWorkflow(applyingWorkflow);
        await sendMessage(prompt, { workflow: applyingWorkflow, bypassWorkflowCommands: true });
        setActiveWorkflow(null);
    }, [activeWorkflow, sendMessage]);

    return {
        messages, isLoading, activeToolName, sessionUsage,
        apiHistory, conversationId,
        pendingConfirmation,
        pendingNavUrl, clearNavUrl: () => setPendingNavUrl(null),
        confirmPendingAction, cancelPendingAction,
        activeWorkflow,
        startWorkflow,
        cancelWorkflow,
        updateWorkflowAnswer,
        goToNextWorkflowStep,
        goToPreviousWorkflowStep,
        setWorkflowStep,
        submitWorkflow,
        sendMessage, clearMessages, loadMessages,
    };
}
