import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { extractErrorMessage } from '../api/client';

function formatDate(value) {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? ''
        : date.toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' });
}

export default function PostCard({ post, currentUserId, onUpdated, onDeleted }) {
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(post.text);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [menuOpen, setMenuOpen] = useState(false);
    const menuRef = useRef(null);

    const author = post.author;
    const authorId = author?.id || post.author_user_id;
    const fullName = author ? `${author.first_name} ${author.second_name}` : 'Пользователь';
    const isOwn = Boolean(currentUserId) && authorId === currentUserId;
    const initial = author?.first_name?.charAt(0) || '?';
    const meta = formatDate(post.created_at);

    useEffect(() => {
        if (!menuOpen) {
            return undefined;
        }

        function closeOnOutsideClick(event) {
            if (menuRef.current && !menuRef.current.contains(event.target)) {
                setMenuOpen(false);
            }
        }

        function closeOnEscape(event) {
            if (event.key === 'Escape') {
                setMenuOpen(false);
            }
        }

        document.addEventListener('mousedown', closeOnOutsideClick);
        document.addEventListener('keydown', closeOnEscape);

        return () => {
            document.removeEventListener('mousedown', closeOnOutsideClick);
            document.removeEventListener('keydown', closeOnEscape);
        };
    }, [menuOpen]);

    function startEditing() {
        setMenuOpen(false);
        setDraft(post.text);
        setError('');
        setEditing(true);
    }

    function cancelEditing() {
        setEditing(false);
        setError('');
    }

    async function saveEditing() {
        const text = draft.trim();

        if (!text) {
            setError('Текст поста не может быть пустым');
            return;
        }

        setBusy(true);
        setError('');

        try {
            await api.put('/post/update', { id: post.id, text });
            onUpdated?.({ ...post, text });
            setEditing(false);
        } catch (requestError) {
            setError(extractErrorMessage(requestError));
        } finally {
            setBusy(false);
        }
    }

    async function removePost() {
        setMenuOpen(false);

        if (!window.confirm('Удалить пост?')) {
            return;
        }

        setBusy(true);
        setError('');

        try {
            await api.put(`/post/delete/${post.id}`);
            onDeleted?.(post.id);
        } catch (requestError) {
            setError(extractErrorMessage(requestError));
            setBusy(false);
        }
    }

    return (
        <article className="post-card panel">
            <header className="post-header">
                <span className="post-avatar">{initial}</span>
                <div className="post-head-main">
                    {author?.id ? (
                        <Link className="post-author-link" to={`/profile/${author.id}`}>
                            {fullName}
                        </Link>
                    ) : (
                        <strong className="post-author-link">{fullName}</strong>
                    )}
                    {meta ? <span className="muted post-meta">{meta}</span> : null}
                </div>

                {isOwn ? (
                    <div className="post-menu-wrap" ref={menuRef}>
                        <button
                            className="post-menu-btn"
                            type="button"
                            aria-label="Действия с постом"
                            aria-haspopup="menu"
                            aria-expanded={menuOpen}
                            onClick={() => setMenuOpen((open) => !open)}
                        >
                            ⋯
                        </button>

                        {menuOpen ? (
                            <div className="post-menu" role="menu">
                                <button
                                    type="button"
                                    role="menuitem"
                                    onClick={startEditing}
                                    disabled={busy || editing}
                                >
                                    Редактировать
                                </button>
                                <button
                                    className="post-menu-danger"
                                    type="button"
                                    role="menuitem"
                                    onClick={removePost}
                                    disabled={busy || editing}
                                >
                                    Удалить
                                </button>
                            </div>
                        ) : null}
                    </div>
                ) : null}
            </header>

            {editing ? (
                <div className="post-edit">
                    <textarea
                        className="composer-input"
                        rows={3}
                        maxLength={5000}
                        value={draft}
                        onChange={(event) => setDraft(event.target.value)}
                        disabled={busy}
                    />
                    <div className="nav-row">
                        <button
                            className="btn btn-gradient btn-sm"
                            type="button"
                            onClick={saveEditing}
                            disabled={busy || !draft.trim()}
                        >
                            {busy ? 'Сохраняем...' : 'Сохранить'}
                        </button>
                        <button
                            className="btn btn-ghost btn-sm"
                            type="button"
                            onClick={cancelEditing}
                            disabled={busy}
                        >
                            Отмена
                        </button>
                    </div>
                </div>
            ) : (
                <p className="post-text">{post.text}</p>
            )}

            {error ? <div className="alert alert-error">{error}</div> : null}

            {/*
                Кнопки реакций пока не реализованы — скрыты до появления API.
                <div className="post-actions muted">
                    <button type="button">Нравится</button>
                    <button type="button">Комментировать</button>
                    <button type="button">Поделиться</button>
                </div>
            */}
        </article>
    );
}
