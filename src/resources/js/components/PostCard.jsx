import { Link } from 'react-router-dom';

function formatDate(value) {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? ''
        : date.toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' });
}

export default function PostCard({ post, currentUserId }) {
    const author = post.author;
    const fullName = author ? `${author.first_name} ${author.second_name}` : 'Пользователь';
    const isOwn = author?.id === currentUserId;
    const initial = author?.first_name?.charAt(0) || '?';
    const meta = [isOwn ? fullName : null, formatDate(post.created_at)].filter(Boolean).join(' · ');

    return (
        <article className="post-card panel">
            <header className="post-header">
                <span className="post-avatar">{initial}</span>
                <div>
                    {author?.id ? (
                        <Link className="post-author-link" to={`/profile/${author.id}`}>
                            {isOwn ? 'Вы' : fullName}
                        </Link>
                    ) : (
                        <strong>{fullName}</strong>
                    )}
                    {meta ? <span className="muted post-meta">{meta}</span> : null}
                </div>
            </header>
            <p className="post-text">{post.text}</p>
            <div className="post-actions muted">
                <button type="button">Нравится</button>
                <button type="button">Комментировать</button>
                <button type="button">Поделиться</button>
            </div>
        </article>
    );
}
