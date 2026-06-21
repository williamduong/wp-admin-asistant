import { useState, useEffect, useCallback, useMemo } from 'react';
import { listConversations, updateConversation, loadConversation, archiveConversation } from '../lib/api';
import styles from '../styles/conversations.module.css';

export default function ConversationManager({ currentConversationId, onLoad, onClose }) {
    const [conversations, setConversations] = useState([]);
    const [loading,       setLoading]       = useState(true);
    const [error,         setError]         = useState(null);
    const [query,         setQuery]         = useState('');
    const [sortBy,        setSortBy]        = useState('recent');
    const [editingId,     setEditingId]     = useState(null);
    const [draftTitle,    setDraftTitle]    = useState('');
    const [renamingId,    setRenamingId]    = useState(null);
    const [archivingId,   setArchivingId]   = useState(null);

    const refresh = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const rows = await listConversations();
            setConversations(rows);
        } catch {
            setError('Could not load conversations.');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { refresh(); }, [refresh]);

    const handleLoad = async (id) => {
        try {
            const data = await loadConversation(id);
            onLoad(data.messages ?? [], data.usage ?? null, data.history ?? [], data.id ?? id, data.meta ?? null);
            onClose();
        } catch {
            setError('Could not load conversation.');
        }
    };

    const handleRenameStart = (e, conversation) => {
        e.stopPropagation();
        setEditingId(conversation.id);
        setDraftTitle(conversation.title ?? '');
    };

    const handleRenameCancel = (e) => {
        e?.stopPropagation();
        setEditingId(null);
        setDraftTitle('');
    };

    const handleRenameSubmit = async (e, id) => {
        e.preventDefault();
        e.stopPropagation();

        const nextTitle = draftTitle.trim();
        if (!nextTitle) {
            setError('Session name cannot be empty.');
            return;
        }

        setRenamingId(id);
        setError(null);
        try {
            await updateConversation(id, { title: nextTitle });
            setConversations(prev => prev.map(conversation =>
                conversation.id === id
                    ? { ...conversation, title: nextTitle, updated_at: new Date().toISOString() }
                    : conversation
            ));
            setEditingId(null);
            setDraftTitle('');
        } catch {
            setError('Could not rename session.');
        } finally {
            setRenamingId(null);
        }
    };

    const handleArchive = async (e, id) => {
        e.stopPropagation();
        if (!confirm('Archive this session from history?')) return;
        try {
            setArchivingId(id);
            await archiveConversation(id);
            setConversations(prev => prev.filter(c => c.id !== id));
        } catch {
            setError('Could not archive session.');
        } finally {
            setArchivingId(null);
        }
    };

    const formatDate = (dateStr) => {
        try {
            return new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(dateStr));
        } catch { return dateStr; }
    };

    const filteredConversations = useMemo(() => {
        const normalizedQuery = query.trim().toLowerCase();
        const visible = conversations.filter(conversation => conversation.id !== currentConversationId);
        const matches = normalizedQuery === ''
            ? visible
            : visible.filter(conversation => (conversation.title ?? '').toLowerCase().includes(normalizedQuery));

        const sorted = [...matches];
        sorted.sort((left, right) => {
            if (sortBy === 'oldest') {
                return new Date(left.updated_at).getTime() - new Date(right.updated_at).getTime();
            }
            if (sortBy === 'title_asc') {
                return (left.title ?? '').localeCompare(right.title ?? '');
            }
            if (sortBy === 'title_desc') {
                return (right.title ?? '').localeCompare(left.title ?? '');
            }
            return new Date(right.updated_at).getTime() - new Date(left.updated_at).getTime();
        });

        return sorted;
    }, [conversations, currentConversationId, query, sortBy]);

    return (
        <div className={styles.manager}>
            <div className={styles.managerHeader}>
                <span>Session History</span>
                <button className={styles.backBtn} onClick={onClose} title="Back to chat">← Back</button>
            </div>

            <div className={styles.toolbar}>
                <input
                    className={styles.searchInput}
                    type="search"
                    placeholder="Search sessions"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    aria-label="Search sessions"
                />
                <select
                    className={styles.sortSelect}
                    value={sortBy}
                    onChange={(e) => setSortBy(e.target.value)}
                    aria-label="Sort sessions"
                >
                    <option value="recent">Newest</option>
                    <option value="oldest">Oldest</option>
                    <option value="title_asc">A-Z</option>
                    <option value="title_desc">Z-A</option>
                </select>
            </div>

            {error && <div className={styles.error}>{error}</div>}

            <div className={styles.list}>
                {loading && <div className={styles.empty}>Loading…</div>}

                {!loading && filteredConversations.length === 0 && (
                    <div className={styles.empty}>
                        {query.trim() ? 'No sessions match your search.' : 'No saved sessions yet.'}
                    </div>
                )}

                {filteredConversations.map(c => (
                    <div key={c.id} className={styles.item} onClick={() => editingId === null && handleLoad(c.id)}>
                        {editingId === c.id ? (
                            <form className={styles.renameForm} onSubmit={(e) => handleRenameSubmit(e, c.id)}>
                                <input
                                    className={styles.renameInput}
                                    value={draftTitle}
                                    onChange={(e) => setDraftTitle(e.target.value)}
                                    autoFocus
                                    maxLength={120}
                                    aria-label="Session name"
                                />
                                <div className={styles.itemActions}>
                                    <button
                                        className={styles.actionBtnPrimary}
                                        type="submit"
                                        disabled={renamingId === c.id}
                                    >
                                        {renamingId === c.id ? 'Saving…' : 'Save'}
                                    </button>
                                    <button
                                        className={styles.actionBtn}
                                        type="button"
                                        onClick={handleRenameCancel}
                                    >
                                        Cancel
                                    </button>
                                </div>
                            </form>
                        ) : (
                            <>
                                <div className={styles.itemTitle}>{c.title}</div>
                                <div className={styles.itemMeta}>{`Session #${c.id} · ${formatDate(c.updated_at)}`}</div>
                                <div className={styles.itemActions}>
                                    <button
                                        className={styles.actionBtn}
                                        onClick={(e) => handleRenameStart(e, c)}
                                        title="Rename session"
                                    >
                                        Rename
                                    </button>
                                    <button
                                        className={styles.archiveBtn}
                                        onClick={(e) => handleArchive(e, c.id)}
                                        title="Archive session"
                                        disabled={archivingId === c.id}
                                    >
                                        {archivingId === c.id ? 'Archiving…' : 'Archive'}
                                    </button>
                                </div>
                            </>
                        )}
                    </div>
                ))}
            </div>
        </div>
    );
}
