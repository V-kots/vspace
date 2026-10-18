import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import api, { setAuthToken } from '../api/client';
import AppShell from '../components/AppShell';

export default function Cabinet() {
    const navigate = useNavigate();
    const [userId, setUserId] = useState('');
    const [profile, setProfile] = useState(null);

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
    }, [navigate]);

    if (!userId) {
        return <div className="app-loading muted">Загрузка...</div>;
    }

    const displayName = profile
        ? `${profile.first_name} ${profile.second_name}`
        : 'Пользователь';

    return (
        <AppShell userId={userId}>
            <div className="feed-search desktop-only">
                <input type="search" className="search-input" placeholder="Поиск людей, сообществ..." aria-label="Поиск" />
            </div>

            {/* <div className="composer panel">
                <div className="composer-avatar">{displayName.charAt(0)}</div>
                <input type="text" className="composer-input" placeholder="Что у вас нового?" readOnly />
                <div className="composer-actions muted">
                    <span>Фото</span>
                    <span>Видео</span>
                    <span>Настроение</span>
                </div>
            </div> */}

            <article className="post-card panel">
                <header className="post-header">
                    <span className="post-avatar">{displayName.charAt(0)}</span>
                    <div>
                        <strong>{displayName}</strong>
                        <span className="muted post-meta">Только что · {profile?.city || 'VSpace'}</span>
                    </div>
                </header>
                <p className="post-text">
                    Добро пожаловать в VSpace! Лента, сообщества и сообщения появятся в следующих версиях.
                    Пока вы можете просмотреть свою анкету и поделиться ID для тестов API.
                </p>
                <div className="post-actions muted">
                    <button type="button">Нравится</button>
                    <button type="button">Комментировать</button>
                    <button type="button">Поделиться</button>
                </div>
            </article>

            <div className="cabinet-card panel">
                <h2 className="section-title">Личный кабинет</h2>
                <p className="muted">ID для входа и API:</p>
                <code className="user-id-value">{userId}</code>
                <div className="nav-row">
                    <Link className="btn btn-gradient" to={`/profile/${userId}`}>Моя анкета</Link>
                </div>
            </div>
        </AppShell>
    );
}
