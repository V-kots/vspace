import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api, { extractErrorMessage } from '../api/client';
import AppShell from '../components/AppShell';

export default function Friends() {
    const navigate = useNavigate();
    const [userId, setUserId] = useState('');
    const [friends, setFriends] = useState(null);
    const [error, setError] = useState('');
    const [actionError, setActionError] = useState('');
    const [busyId, setBusyId] = useState('');

    useEffect(() => {
        const token = localStorage.getItem('vspace_token');
        const storedId = localStorage.getItem('vspace_user_id');

        if (!token || !storedId) {
            navigate('/login');
            return;
        }

        setUserId(storedId);
    }, [navigate]);

    useEffect(() => {
        if (!userId) {
            return undefined;
        }

        let cancelled = false;

        setError('');
        setFriends(null);

        api.get('/friend/list')
            .then(({ data }) => {
                if (!cancelled) {
                    setFriends(data);
                }
            })
            .catch((requestError) => {
                if (!cancelled) {
                    setError(extractErrorMessage(requestError));
                    setFriends([]);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [userId]);

    if (!userId) {
        return <div className="app-loading muted">Загрузка...</div>;
    }

    async function removeFriend(friendId) {
        setBusyId(friendId);
        setActionError('');

        try {
            await api.put(`/friend/delete/${friendId}`);
            setFriends((current) => (current || []).filter((friend) => friend.id !== friendId));
        } catch (requestError) {
            setActionError(extractErrorMessage(requestError));
        } finally {
            setBusyId('');
        }
    }

    return (
        <AppShell userId={userId}>
            <div className="panel search-panel">
                <h1 className="section-title">Друзья</h1>
                <p className="muted search-hint">
                    Пользователи, которых вы добавили в друзья.
                </p>
            </div>

            {error ? <div className="alert alert-error">{error}</div> : null}
            {actionError ? <div className="alert alert-error">{actionError}</div> : null}

            {friends === null && !error ? (
                <div className="panel profile-empty muted">Загружаем друзей...</div>
            ) : null}

            {friends !== null && !error ? (
                <div className="search-results-wrap">
                    <p className="muted search-count">
                        Всего: <strong>{friends.length}</strong>
                    </p>

                    {friends.length === 0 ? (
                        <div className="panel search-empty muted">
                            Друзей пока нет. <Link className="link" to="/search">Найти людей</Link>
                        </div>
                    ) : (
                        <ul className="search-results">
                            {friends.map((friend) => (
                                <li key={friend.id}>
                                    <div className="panel search-result">
                                        <Link className="search-result-link" to={`/profile/${friend.id}`}>
                                            <span className="search-result-avatar">
                                                {friend.first_name?.charAt(0) || '?'}
                                            </span>
                                            <span className="search-result-body">
                                                <span className="search-result-name">
                                                    {friend.first_name} {friend.second_name}
                                                </span>
                                                <span className="muted search-result-meta">
                                                    {friend.city}
                                                    {friend.birthdate ? ` · ${friend.birthdate}` : ''}
                                                </span>
                                                {friend.biography ? (
                                                    <span className="search-result-bio">{friend.biography}</span>
                                                ) : null}
                                            </span>
                                        </Link>
                                        <button
                                            className="btn btn-ghost btn-sm search-result-action"
                                            type="button"
                                            onClick={() => removeFriend(friend.id)}
                                            disabled={busyId === friend.id}
                                        >
                                            {busyId === friend.id ? 'Удаляем...' : 'Удалить'}
                                        </button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            ) : null}
        </AppShell>
    );
}
