import { useState } from 'react';
import { Link, NavLink, useLocation, useNavigate } from 'react-router-dom';
import Logo from './Logo';
import { setAuthToken } from '../api/client';

function mainNav(profilePath) {
    return [
        { to: '/cabinet', label: 'Главная', icon: 'home' },
        { to: profilePath, label: 'Профиль', icon: 'profile' },
        // { to: '/cabinet', label: 'Лента', icon: 'feed', end: false },
        // { to: '/cabinet', label: 'Сообщения', icon: 'messages', badge: 3 },
        // { to: '/cabinet', label: 'Друзья', icon: 'friends' },
        // { to: '/cabinet', label: 'Сообщества', icon: 'groups' },
    ];
}

function drawerNav(profilePath) {
    return [
        { to: '/cabinet', label: 'Главная', icon: 'home' },
        { to: profilePath, label: 'Профиль', icon: 'profile' },
        // { to: '/cabinet', label: 'Друзья', icon: 'friends' },
        // { to: '/cabinet', label: 'Сообщества', icon: 'groups' },
        { to: '/cabinet', label: 'Закладки', icon: 'bookmark' },
        { to: '/cabinet', label: 'Настройки', icon: 'settings' },
    ];
}

function NavIcon({ name }) {
    const paths = {
        home: 'M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1v-9.5Z',
        feed: 'M4 5h16v2H4V5Zm0 5h16v2H4v-2Zm0 5h10v2H4v-2Z',
        messages: 'M4 6h16v9H6l-2 2V6Z',
        friends: 'M8 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm8 0a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5ZM3 19a5 5 0 0 1 10 0H3Zm10 0a4 4 0 0 1 8 0h-8Z',
        groups: 'M6 8a2 2 0 1 0 0-4 2 2 0 0 0 0 4Zm12 0a2 2 0 1 0 0-4 2 2 0 0 0 0 4ZM2 18a4 4 0 0 1 8 0H2Zm12 0a3.5 3.5 0 0 1 7 0h-7Z',
        profile: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-7 8a7 7 0 0 1 14 0H5Z',
        bookmark: 'M6 4h12v16l-6-4-6 4V4Z',
        settings: 'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Zm8.5 4a6.5 6.5 0 0 1-.2 1.5l2 1.5-2 3.5-2.3-1a6.7 6.7 0 0 1-2.6 1.5l-.4 2.5H9l-.4-2.5a6.7 6.7 0 0 1-2.6-1.5l-2.3 1-2-3.5 2-1.5A6.5 6.5 0 0 1 3.5 12a6.5 6.5 0 0 1 .2-1.5l-2-1.5 2-3.5 2.3 1a6.7 6.7 0 0 1 2.6-1.5L9 3.5h6l.4 2.5a6.7 6.7 0 0 1 2.6 1.5l2.3-1 2 3.5-2 1.5c.1.5.2 1 .2 1.5Z',
        search: 'M10 4a6 6 0 1 1 0 12 6 6 0 0 1 0-12Zm8 14-4-4 1.5-1.5 4 4L18 18Z',
        bell: 'M12 4a5 5 0 0 1 5 5v3l2 2v1H5v-1l2-2V9a5 5 0 0 1 5-5Zm0 16a2.5 2.5 0 0 0 2.5-2.5h-5A2.5 2.5 0 0 0 12 20Z',
        menu: 'M4 7h16v2H4V7Zm0 5h16v2H4v-2Zm0 5h16v2H4v-2Z',
        plus: 'M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6V5Z',
    };

    return (
        <svg className="nav-icon" viewBox="0 0 24 24" aria-hidden="true">
            <path fill="currentColor" d={paths[name] || paths.home} />
        </svg>
    );
}

export default function AppShell({ userId, children, variant = 'default' }) {
    const navigate = useNavigate();
    const location = useLocation();
    const [drawerOpen, setDrawerOpen] = useState(false);
    const profilePath = userId ? `/profile/${userId}` : '/cabinet';

    function logout() {
        setAuthToken(null);
        localStorage.removeItem('vspace_user_id');
        navigate('/login');
    }

    const isProfile = location.pathname.startsWith('/profile');
    const navItems = mainNav(profilePath);
    const drawerItems = drawerNav(profilePath);

    function sidebarLinkClass(item, isActive) {
        if (item.icon === 'home') {
            return `sidebar-link ${isActive && !isProfile ? 'active' : ''}`;
        }
        if (item.icon === 'profile') {
            return `sidebar-link ${isActive || isProfile ? 'active' : ''}`;
        }
        return `sidebar-link ${isActive ? 'active' : ''}`;
    }

    return (
        <div className={`app-layout ${variant === 'profile' ? 'app-layout-profile' : ''}`}>
            <aside className="app-sidebar desktop-only">
                <div className="sidebar-brand">
                    <Logo size="sm" to="/cabinet" />
                </div>
                <nav className="sidebar-nav">
                    {navItems.map((item) => (
                        <NavLink
                            key={item.label}
                            to={item.to}
                            className={({ isActive }) => sidebarLinkClass(item, isActive)}
                            end={item.icon === 'home'}
                        >
                            <NavIcon name={item.icon} />
                            <span>{item.label}</span>
                            {item.badge ? <span className="nav-badge">{item.badge}</span> : null}
                        </NavLink>
                    ))}
                </nav>
            </aside>

            <div className="app-main">
                <header className="app-topbar mobile-only">
                    <button type="button" className="icon-btn" aria-label="Меню" onClick={() => setDrawerOpen(true)}>
                        <NavIcon name="menu" />
                    </button>
                    <div className="topbar-logo">
                        <Logo size="sm" to="/cabinet" />
                    </div>
                    <div className="topbar-actions">
                        <button type="button" className="icon-btn" aria-label="Поиск">
                            <NavIcon name="search" />
                        </button>
                        <button type="button" className="icon-btn" aria-label="Уведомления">
                            <NavIcon name="bell" />
                            <span className="nav-badge nav-badge-dot" />
                        </button>
                    </div>
                </header>

                {variant === 'default' && (
                    <div className="stories-strip mobile-only">
                        {['Вы', 'Анна', 'Макс', 'Катя', 'Игорь'].map((name) => (
                            <div key={name} className="story-item">
                                <div className="story-ring">
                                    <span className="story-avatar">{name.charAt(0)}</span>
                                </div>
                                <span className="story-name">{name}</span>
                            </div>
                        ))}
                    </div>
                )}

                <div className="app-content-grid">
                    <main className="app-content">{children}</main>
                    <aside className="app-widgets desktop-only">
                        {/* <div className="widget panel">
                            <h3 className="widget-title">Популярные сообщества</h3>
                            <ul className="widget-list">
                                <li>
                                    <span>Путешествия</span>
                                    <button type="button" className="btn btn-sm btn-outline">Подписаться</button>
                                </li>
                                <li>
                                    <span>Фотография</span>
                                    <button type="button" className="btn btn-sm btn-outline">Подписаться</button>
                                </li>
                            </ul>
                        </div> */}
                        {/* <div className="widget panel">
                            <h3 className="widget-title">Интересные люди</h3>
                            <ul className="widget-list widget-people">
                                <li>
                                    <span className="widget-avatar">А</span>
                                    <span>Анна К.</span>
                                    <button type="button" className="btn btn-sm btn-outline">Добавить</button>
                                </li>
                                <li>
                                    <span className="widget-avatar">М</span>
                                    <span>Максим П.</span>
                                    <button type="button" className="btn btn-sm btn-outline">Добавить</button>
                                </li>
                            </ul>
                        </div> */}
                    </aside>
                </div>
            </div>

            <nav className="bottom-nav mobile-only" aria-label="Основная навигация">
                <NavLink to="/cabinet" className={({ isActive }) => `bottom-nav-item ${isActive && !isProfile ? 'active' : ''}`} end>
                    <NavIcon name="home" />
                    <span>Главная</span>
                </NavLink>
                <NavLink to={profilePath} className={({ isActive }) => `bottom-nav-item ${isActive || isProfile ? 'active' : ''}`}>
                    <NavIcon name="profile" />
                    <span>Профиль</span>
                </NavLink>
                {/* <NavLink to="/cabinet" className="bottom-nav-item">
                    <NavIcon name="feed" />
                    <span>Лента</span>
                </NavLink> */}
                <button type="button" className="bottom-nav-fab" aria-label="Создать">
                    <NavIcon name="plus" />
                </button>
                {/* <NavLink to="/cabinet" className="bottom-nav-item">
                    <NavIcon name="messages" />
                    <span>Чаты</span>
                </NavLink> */}
            </nav>

            {drawerOpen && (
                <div className="drawer-backdrop" role="presentation" onClick={() => setDrawerOpen(false)} />
            )}
            <aside className={`app-drawer mobile-only ${drawerOpen ? 'open' : ''}`} aria-hidden={!drawerOpen}>
                <div className="drawer-header">
                    <Logo
                        size="sm"
                        to="/cabinet"
                        onNavigate={() => setDrawerOpen(false)}
                    />
                    <button type="button" className="icon-btn" aria-label="Закрыть" onClick={() => setDrawerOpen(false)}>×</button>
                </div>
                <nav className="drawer-nav">
                    {drawerItems.map((item) => (
                        <Link
                            key={item.label}
                            to={item.to}
                            className="drawer-link"
                            onClick={() => setDrawerOpen(false)}
                        >
                            <NavIcon name={item.icon} />
                            {item.label}
                        </Link>
                    ))}
                </nav>
                <button type="button" className="btn btn-ghost drawer-logout" onClick={logout}>
                    Выйти
                </button>
            </aside>
        </div>
    );
}
