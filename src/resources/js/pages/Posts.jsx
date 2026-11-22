import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api, { extractErrorMessage } from '../api/client';
import AppShell from '../components/AppShell';
import PostCard from '../components/PostCard';

const PAGE_SIZE = 25;

export default function Posts() {
    const navigate = useNavigate();
    const [userId, setUserId] = useState('');
    const [profile, setProfile] = useState(null);
    const [posts, setPosts] = useState([]);
    const [loading, setLoading] = useState(true);
    const [loadingMore, setLoadingMore] = useState(false);
    const [hasMore, setHasMore] = useState(false);
    const [error, setError] = useState('');
    const [postText, setPostText] = useState('');
    const [postError, setPostError] = useState('');
    const [posting, setPosting] = useState(false);
    const sentinelRef = useRef(null);
    const loadingMoreRef = useRef(false);

    useEffect(() => {
        const token = localStorage.getItem('vspace_token');
        const storedId = localStorage.getItem('vspace_user_id');

        if (!token || !storedId) {
            navigate('/login');
            return;
        }

        setUserId(storedId);
        api.get(`/user/get/${storedId}`).then(({ data }) => setProfile(data)).catch(() => {});
    }, [navigate]);

    useEffect(() => {
        if (!userId) {
            return undefined;
        }

        let cancelled = false;

        setLoading(true);
        setError('');

        api.get('/post/feed', { params: { offset: 0, limit: PAGE_SIZE } })
            .then(({ data }) => {
                if (!cancelled) {
                    setPosts(data);
                    setHasMore(data.length === PAGE_SIZE);
                }
            })
            .catch((requestError) => {
                if (!cancelled) {
                    setError(extractErrorMessage(requestError));
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [userId]);

    async function loadMore() {
        if (loadingMoreRef.current || !hasMore) {
            return;
        }

        loadingMoreRef.current = true;
        setLoadingMore(true);

        try {
            const { data } = await api.get('/post/feed', {
                params: { offset: posts.length, limit: PAGE_SIZE },
            });

            setPosts((current) => [...current, ...data]);
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
    }, [hasMore, loading, loadingMore, posts]);

    async function onCreatePost(event) {
        event.preventDefault();

        const text = postText.trim();

        if (!text) {
            setPostError('Введите текст поста');
            return;
        }

        setPosting(true);
        setPostError('');

        try {
            await api.post('/post/create', { text });
            setPostText('');

            const { data } = await api.get('/post/feed', {
                params: { offset: 0, limit: PAGE_SIZE },
            });

            setPosts(data);
            setHasMore(data.length === PAGE_SIZE);
        } catch (requestError) {
            setPostError(extractErrorMessage(requestError));
        } finally {
            setPosting(false);
        }
    }

    if (!userId) {
        return <div className="app-loading muted">Загрузка...</div>;
    }

    const displayName = profile
        ? `${profile.first_name} ${profile.second_name}`
        : 'Пользователь';

    return (
        <AppShell userId={userId}>
            <div className="panel">
                <h1 className="section-title">Посты</h1>
                <p className="muted">Посты вас и ваших друзей. Прокрутите вниз, чтобы загрузить ещё.</p>
            </div>

            <form className="composer panel" onSubmit={onCreatePost}>
                <div className="composer-avatar">{displayName.charAt(0)}</div>
                <textarea
                    className="composer-input"
                    placeholder="Что у вас нового?"
                    rows={3}
                    maxLength={5000}
                    value={postText}
                    onChange={(event) => setPostText(event.target.value)}
                    disabled={posting}
                />
                <div className="composer-actions">
                    <button className="btn btn-gradient btn-sm" type="submit" disabled={posting || !postText.trim()}>
                        {posting ? 'Публикуем...' : 'Опубликовать'}
                    </button>
                </div>
            </form>

            {postError ? <div className="alert alert-error">{postError}</div> : null}

            {error ? <div className="alert alert-error">{error}</div> : null}

            {loading ? <div className="panel profile-empty muted">Загружаем посты...</div> : null}

            {!loading && posts.length === 0 && !error ? (
                <div className="panel profile-empty muted">Постов пока нет.</div>
            ) : null}

            <div className="post-feed">
                {posts.map((post) => (
                    <PostCard
                        key={post.id}
                        post={post}
                        currentUserId={userId}
                        onUpdated={(updated) => setPosts((current) => current.map((item) => (
                            item.id === updated.id ? updated : item
                        )))}
                        onDeleted={(postId) => setPosts((current) => current.filter((item) => item.id !== postId))}
                    />
                ))}
            </div>

            {hasMore ? (
                <div ref={sentinelRef} className="infinite-sentinel">
                    {loadingMore ? (
                        <span className="muted infinite-loader">Загружаем ещё...</span>
                    ) : null}
                </div>
            ) : null}
        </AppShell>
    );
}
