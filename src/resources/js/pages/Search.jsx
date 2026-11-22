import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import api, { extractErrorMessage } from '../api/client';
import AppShell from '../components/AppShell';
import UserSearchForm from '../components/UserSearchForm';

const PAGE_SIZE = 25;

function readTotal(headers, fallback) {
    const value = Number(headers?.['x-total-count']);

    return Number.isNaN(value) ? fallback : value;
}

export default function Search() {
    const navigate = useNavigate();
    const [params, setParams] = useSearchParams();
    const [userId, setUserId] = useState('');
    const [results, setResults] = useState(null);
    const [total, setTotal] = useState(null);
    const [loading, setLoading] = useState(false);
    const [loadingMore, setLoadingMore] = useState(false);
    const [hasMore, setHasMore] = useState(false);
    const [error, setError] = useState('');
    const [friendIds, setFriendIds] = useState({});
    const [friendsReady, setFriendsReady] = useState(false);
    const [friendBusyId, setFriendBusyId] = useState('');
    const [friendError, setFriendError] = useState('');
    const lastQuery = useRef('');
    const queryRef = useRef({ first: '', last: '' });
    const sentinelRef = useRef(null);
    const loadingMoreRef = useRef(false);

    const queryString = params.toString();

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

        async function loadFriends() {
            try {
                const { data } = await api.get('/friend/list');

                if (!cancelled) {
                    setFriendIds(Object.fromEntries(data.map((friend) => [friend.id, true])));
                }
            } catch {
                // Список друзей не критичен для поиска: кнопки останутся нейтральными.
            } finally {
                if (!cancelled) {
                    setFriendsReady(true);
                }
            }
        }

        loadFriends();

        return () => {
            cancelled = true;
        };
    }, [userId]);

    useEffect(() => {
        const first = params.get('first_name');
        const last = params.get('last_name');

        if (!first || !last || lastQuery.current === queryString) {
            return;
        }

        lastQuery.current = queryString;
        search(first, last);
    }, [queryString]);

    async function search(first, last) {
        queryRef.current = { first, last };
        setLoading(true);
        setLoadingMore(false);
        setError('');

        try {
            const { data, headers } = await api.get('/user/search', {
                params: { first_name: first, last_name: last, offset: 0, limit: PAGE_SIZE },
            });
            setResults(data);
            setTotal(readTotal(headers, data.length));
            setHasMore(data.length === PAGE_SIZE);
        } catch (requestError) {
            setError(extractErrorMessage(requestError));
            setResults([]);
            setTotal(null);
            setHasMore(false);
        } finally {
            setLoading(false);
        }
    }

    async function loadMore() {
        if (loadingMoreRef.current || loading || !hasMore || results === null) {
            return;
        }

        loadingMoreRef.current = true;
        setLoadingMore(true);

        try {
            const { first, last } = queryRef.current;
            const { data, headers } = await api.get('/user/search', {
                params: { first_name: first, last_name: last, offset: results.length, limit: PAGE_SIZE },
            });

            setResults((current) => [...(current || []), ...data]);
            setTotal(readTotal(headers, results.length + data.length));
            setHasMore(data.length === PAGE_SIZE);
        } catch (requestError) {
            setError(extractErrorMessage(requestError));
        } finally {
            loadingMoreRef.current = false;
            setLoadingMore(false);
        }
    }

    useEffect(() => {
        const node = sentinelRef.current;

        if (!node || !hasMore || loading || loadingMore) {
            return undefined;
        }

        const observer = new IntersectionObserver(
            (entries) => {
                if (entries[0]?.isIntersecting) {
                    loadMore();
                }
            },
            { rootMargin: '200px' },
        );

        observer.observe(node);

        return () => observer.disconnect();
    }, [hasMore, loading, loadingMore, results]);

    function handleSubmit(first, last) {
        setParams({ first_name: first, last_name: last });
    }

    async function toggleFriend(profileId) {
        setFriendBusyId(profileId);
        setFriendError('');

        try {
            if (friendIds[profileId]) {
                await api.put(`/friend/delete/${profileId}`);
                setFriendIds((current) => ({ ...current, [profileId]: false }));
            } else {
                await api.put(`/friend/set/${profileId}`);
                setFriendIds((current) => ({ ...current, [profileId]: true }));
            }
        } catch (requestError) {
            setFriendError(extractErrorMessage(requestError));
        } finally {
            setFriendBusyId('');
        }
    }

    if (!userId) {
        return <div className="app-loading muted">Загрузка...</div>;
    }

    const searched = results !== null;

    return (
        <AppShell userId={userId}>
            <div className="panel search-panel">
                <h1 className="section-title">Поиск анкет</h1>
                <p className="muted search-hint">
                    Введите начало имени и фамилии — поиск идёт одновременно по обоим полям.
                </p>
                <UserSearchForm
                    key={queryString}
                    initialFirstName={params.get('first_name') || ''}
                    initialLastName={params.get('last_name') || ''}
                    onSubmit={handleSubmit}
                />
            </div>

            {error ? <div className="alert alert-error">{error}</div> : null}
            {friendError ? <div className="alert alert-error">{friendError}</div> : null}

            {loading ? <div className="app-loading muted">Ищем анкеты...</div> : null}

            {!loading && searched && (
                <div className="search-results-wrap">
                    <p className="muted search-count">
                        Показано: <strong>{results.length}</strong>
                        {total !== null ? (
                            <> из <strong>{total}</strong></>
                        ) : null}
                    </p>

                    {results.length === 0 ? (
                        <div className="panel search-empty muted">
                            По заданным имени и фамилии никого не нашли. Попробуйте укоротить запрос.
                        </div>
                    ) : (
                        <ul className="search-results">
                            {results.map((profile) => {
                                const fullName = `${profile.first_name} ${profile.second_name}`;
                                const isFriend = Boolean(friendIds[profile.id]);
                                const isBusy = friendBusyId === profile.id;

                                return (
                                    <li key={profile.id}>
                                        <div className="panel search-result">
                                            <Link className="search-result-link" to={`/profile/${profile.id}`}>
                                                <span className="search-result-avatar">
                                                    {profile.first_name?.charAt(0) || '?'}
                                                </span>
                                                <span className="search-result-body">
                                                    <span className="search-result-name">{fullName}</span>
                                                    <span className="muted search-result-meta">
                                                        {profile.city}
                                                        {profile.birthdate ? ` · ${profile.birthdate}` : ''}
                                                    </span>
                                                    {profile.biography ? (
                                                        <span className="search-result-bio">{profile.biography}</span>
                                                    ) : null}
                                                </span>
                                            </Link>
                                            {profile.id !== userId && friendsReady ? (
                                                <button
                                                    className={`btn btn-sm search-result-action ${isFriend ? 'btn-ghost' : 'btn-outline'}`}
                                                    type="button"
                                                    onClick={() => toggleFriend(profile.id)}
                                                    disabled={isBusy}
                                                >
                                                    {isBusy ? '...' : isFriend ? 'Удалить' : 'В друзья'}
                                                </button>
                                            ) : null}
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    )}

                    {hasMore ? (
                        <div ref={sentinelRef} className="infinite-sentinel">
                            {loadingMore ? (
                                <span className="muted infinite-loader">Загружаем ещё...</span>
                            ) : null}
                        </div>
                    ) : null}
                </div>
            )}
        </AppShell>
    );
}
