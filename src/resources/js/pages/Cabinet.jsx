import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api, { extractErrorMessage, setAuthToken } from '../api/client';
import AppShell from '../components/AppShell';
import PostCard from '../components/PostCard';

const HOME_FEED_LIMIT = 5;

export default function Cabinet() {
    const navigate = useNavigate();
    const [userId, setUserId] = useState('');
    const [profile, setProfile] = useState(null);
    const [posts, setPosts] = useState([]);
    const [postText, setPostText] = useState('');
    const [postError, setPostError] = useState('');
    const [postsLoading, setPostsLoading] = useState(false);
    const [posting, setPosting] = useState(false);

    useEffect(() => {
        const token = localStorage.getItem('vspace_token');
        const storedId = localStorage.getItem('vspace_user_id');

        if (!token || !storedId) {
            setAuthToken(null);
            navigate('/login');
            return;
        }

        setUserId(storedId);
        api.get(`/user/get/${storedId}`).then(({ data }) => setProfile(data)).catch(() => {});
        loadFeed();
    }, [navigate]);

    async function loadFeed() {
        setPostsLoading(true);
        setPostError('');

        try {
            const { data } = await api.get('/post/feed', {
                params: { offset: 0, limit: HOME_FEED_LIMIT },
            });
            setPosts(data);
        } catch (error) {
            setPostError(extractErrorMessage(error));
        } finally {
            setPostsLoading(false);
        }
    }

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
            await loadFeed();
        } catch (error) {
            setPostError(extractErrorMessage(error));
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
            <div className="cabinet-card panel">
                <h2 className="section-title">Личный кабинет</h2>
                <p className="muted">ID для входа и API:</p>
                <code className="user-id-value">{userId}</code>
                <div className="nav-row">
                    <Link className="btn btn-gradient" to={`/profile/${userId}`}>Моя анкета</Link>
                </div>
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

            <div className="feed-head">
                <h2 className="section-title">Последние посты</h2>
            </div>

            {postsLoading ? <div className="app-loading muted">Загружаем посты...</div> : null}

            {!postsLoading && posts.length === 0 ? (
                <div className="panel profile-empty muted">
                    Постов пока нет. Напишите первый.
                </div>
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

            {!postsLoading && posts.length > 0 ? (
                <Link className="feed-more" to="/posts">
                    Смотреть все посты
                </Link>
            ) : null}
        </AppShell>
    );
}
