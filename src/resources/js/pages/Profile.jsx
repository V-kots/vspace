import { useEffect, useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import api, { extractErrorMessage } from '../api/client';
import AppShell from '../components/AppShell';
import PostCard from '../components/PostCard';

export default function Profile() {
    const { id } = useParams();
    const [profile, setProfile] = useState(null);
    const [serverError, setServerError] = useState('');
    const [tab, setTab] = useState('about');
    const [posts, setPosts] = useState([]);
    const [postsLoading, setPostsLoading] = useState(false);
    const [postsError, setPostsError] = useState('');
    const [friendStatus, setFriendStatus] = useState('loading');
    const [friendBusy, setFriendBusy] = useState(false);
    const [friendError, setFriendError] = useState('');
    const tabsRef = useRef(null);
    const currentUserId = localStorage.getItem('vspace_user_id') || '';
    const isAuthenticated = Boolean(localStorage.getItem('vspace_token'));
    const isOwnProfile = currentUserId === id;

    useEffect(() => {
        setFriendError('');
        setFriendStatus(isAuthenticated && !isOwnProfile ? 'loading' : 'none');

        if (!isAuthenticated || isOwnProfile) {
            return undefined;
        }

        let cancelled = false;

        async function loadFriendStatus() {
            try {
                const { data } = await api.get('/friend/list');

                if (!cancelled) {
                    setFriendStatus(data.some((friend) => friend.id === id) ? 'friend' : 'none');
                }
            } catch {
                if (!cancelled) {
                    // Статус не критичен: по умолчанию показываем «Добавить в друзья».
                    setFriendStatus('none');
                }
            }
        }

        loadFriendStatus();

        return () => {
            cancelled = true;
        };
    }, [id, isAuthenticated, isOwnProfile]);

    useEffect(() => {
        async function load() {
            try {
                const { data } = await api.get(`/user/get/${id}`);
                setProfile(data);
            } catch (error) {
                setServerError(extractErrorMessage(error));
            }
        }
        load();
    }, [id]);

    useEffect(() => {
        if (!isAuthenticated) {
            setPosts([]);
            setPostsLoading(false);
            return undefined;
        }

        let cancelled = false;

        async function loadPosts() {
            setPostsLoading(true);
            setPostsError('');

            try {
                const { data } = await api.get('/post/feed', {
                    params: { author_user_id: id, limit: 100 },
                });

                if (!cancelled) {
                    setPosts(data);
                }
            } catch (error) {
                if (!cancelled) {
                    setPostsError(extractErrorMessage(error));
                }
            } finally {
                if (!cancelled) {
                    setPostsLoading(false);
                }
            }
        }

        loadPosts();

        return () => {
            cancelled = true;
        };
    }, [id, isAuthenticated]);

    async function addFriend() {
        setFriendBusy(true);
        setFriendError('');

        try {
            await api.put(`/friend/set/${id}`);
            setFriendStatus('friend');
        } catch (error) {
            setFriendError(extractErrorMessage(error));
        } finally {
            setFriendBusy(false);
        }
    }

    async function removeFriend() {
        setFriendBusy(true);
        setFriendError('');

        try {
            await api.put(`/friend/delete/${id}`);
            setFriendStatus('none');
        } catch (error) {
            setFriendError(extractErrorMessage(error));
        } finally {
            setFriendBusy(false);
        }
    }

    function openPostsTab() {
        setTab('posts');
        tabsRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    if (serverError && !profile) {
        return (
            <div className="shell">
                <div className="alert alert-error">{serverError}</div>
                <Link className="link" to="/cabinet">Назад в кабинет</Link>
            </div>
        );
    }

    if (!profile) {
        return <div className="app-loading muted">Загрузка анкеты...</div>;
    }

    const fullName = `${profile.first_name} ${profile.second_name}`;
    const initial = profile.first_name?.charAt(0) || '?';

    return (
        <AppShell userId={id} variant="profile">
            <div className="profile-hero">
                <div className="profile-cover" />
                <div className="profile-head panel">
                    <div className="profile-avatar-lg">{initial}</div>
                    <div className="profile-head-main">
                        <h1 className="profile-name">{fullName}</h1>
                        <p className="muted profile-location">{profile.city}</p>
                        <p className="profile-bio">{profile.biography || 'Интересы пока не указаны.'}</p>
                        <div className="profile-stats">
                            <button
                                type="button"
                                className="profile-stat-link profile-stat-button"
                                onClick={openPostsTab}
                            >
                                <strong>{posts.length}</strong>
                                <span className="muted">Постов</span>
                            </button>
                            <div>
                                {isOwnProfile ? (
                                    <Link className="profile-stat-link" to="/friends">
                                        <strong>{profile.friends_count ?? 0}</strong>
                                        <span className="muted">Друзей</span>
                                    </Link>
                                ) : (
                                    <>
                                        <strong>{profile.friends_count ?? 0}</strong>
                                        <span className="muted">Друзей</span>
                                    </>
                                )}
                            </div>
                            {/* <div><strong>0</strong><span className="muted">Подписчиков</span></div> */}
                            {/* <div><strong>0</strong><span className="muted">Подписок</span></div> */}
                        </div>
                        {isAuthenticated && !isOwnProfile ? (
                            <div className="nav-row profile-actions">
                                {friendStatus === 'loading' ? (
                                    <button
                                        className="btn btn-outline btn-sm"
                                        type="button"
                                        disabled
                                    >
                                        ...
                                    </button>
                                ) : friendStatus === 'friend' ? (
                                    <button
                                        className="btn btn-ghost btn-sm"
                                        type="button"
                                        onClick={removeFriend}
                                        disabled={friendBusy}
                                    >
                                        {friendBusy ? 'Удаляем...' : 'Удалить из друзей'}
                                    </button>
                                ) : (
                                    <button
                                        className="btn btn-gradient btn-sm"
                                        type="button"
                                        onClick={addFriend}
                                        disabled={friendBusy}
                                    >
                                        {friendBusy ? 'Добавляем...' : 'Добавить в друзья'}
                                    </button>
                                )}
                            </div>
                        ) : null}
                        {friendError ? <div className="alert alert-error">{friendError}</div> : null}
                        {isAuthenticated && isOwnProfile ? (
                            <p className="muted profile-actions">
                                Чтобы добавить друга, найдите его в <Link className="link" to="/search">поиске анкет</Link> и нажмите «В друзья».
                            </p>
                        ) : null}
                    </div>
                </div>
            </div>

            <div className="profile-tabs" ref={tabsRef}>
                <button type="button" className={tab === 'about' ? 'active' : ''} onClick={() => setTab('about')}>Обо мне</button>
                <button type="button" className={tab === 'posts' ? 'active' : ''} onClick={() => setTab('posts')}>Посты</button>
                <button type="button" className={tab === 'photos' ? 'active' : ''} onClick={() => setTab('photos')}>Фото</button>
            </div>

            {tab === 'about' && (
                <div className="panel profile-about">
                    <div className="profile-meta">
                        <div><strong>Имя:</strong> {profile.first_name}</div>
                        <div><strong>Фамилия:</strong> {profile.second_name}</div>
                        <div><strong>Дата рождения:</strong> {profile.birthdate}</div>
                        <div><strong>Интересы:</strong> {profile.biography || '—'}</div>
                        <div><strong>Город:</strong> {profile.city}</div>
                    </div>
                </div>
            )}

            {tab === 'posts' && (
                <div className="post-feed">
                    {!isAuthenticated ? (
                        <div className="panel profile-empty muted">
                            Войдите, чтобы смотреть посты.
                        </div>
                    ) : null}
                    {isAuthenticated && postsLoading ? <div className="app-loading muted">Загружаем посты...</div> : null}
                    {postsError ? <div className="alert alert-error">{postsError}</div> : null}
                    {isAuthenticated && !postsLoading && !postsError && posts.length === 0 ? (
                        <div className="panel profile-empty muted">
                            Постов пока нет.
                        </div>
                    ) : null}
                    {posts.map((post) => (
                        <PostCard
                            key={post.id}
                            post={post}
                            currentUserId={currentUserId}
                            onUpdated={(updated) => setPosts((current) => current.map((item) => (
                                item.id === updated.id ? updated : item
                            )))}
                            onDeleted={(postId) => setPosts((current) => current.filter((item) => item.id !== postId))}
                        />
                    ))}
                </div>
            )}

            {tab === 'photos' && (
                <div className="panel profile-empty muted">
                    Альбом фото в разработке.
                </div>
            )}
        </AppShell>
    );
}
